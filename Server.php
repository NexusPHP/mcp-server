<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Nexus\Mcp\Server;

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Dispatch\MessageDispatcherInterface;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin shell that drives a single transport's lifecycle, exposing a blocking
 * `run()` and a non-blocking `listen()`.
 */
final readonly class Server
{
    public function __construct(private MessageDispatcherInterface $dispatcher, private LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Runs the server on the transport, blocking until it closes. Use for a
     * long-lived transport that owns its read loop (stdio). For a request-scoped
     * transport the host drives per request, use `listen()` instead.
     */
    public function run(TransportInterface $transport): void
    {
        $this->logger->info('Starting MCP server.');

        $deferred = new DeferredFuture();

        $this->attachDispatchListeners($transport);

        $transport->onClose(static function () use ($deferred): void {
            // Transports may emit `close` more than once if an error raises during shutdown
            // (e.g. stdin EOF followed by a write failure). Ignore the duplicate.
            if ($deferred->isComplete()) {
                return;
            }

            $deferred->complete();
        });

        $transport->start();
        $deferred->getFuture()->await();

        $this->logger->info('MCP server stopped.');
    }

    /**
     * Attaches the dispatcher and starts the transport without blocking. Use for
     * a request-scoped transport (streamable HTTP mounted in a PSR-15 stack) the
     * host drives per request. For a long-lived transport, use `run()` instead.
     */
    public function listen(TransportInterface $transport): void
    {
        $this->attachDispatchListeners($transport);

        $transport->start();
    }

    private function attachDispatchListeners(TransportInterface $transport): void
    {
        $transport->onMessage(function (array $envelope) use ($transport): void {
            $this->dispatcher->dispatch($envelope, $transport);
        });
        $transport->onError(function (\Throwable $e): void {
            $this->logger->error('Transport error.', ['exception' => $e]);
        });
        $transport->onDrain(function (): void {
            $this->dispatcher->flushPending();
        });
    }
}
