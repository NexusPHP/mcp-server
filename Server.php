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
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Server\Dispatch\MessageDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin shell that drives a single transport's lifecycle. `run()` wires the
 * listeners once and blocks until the transport closes.
 */
final readonly class Server
{
    public function __construct(private MessageDispatcher $dispatcher, private LoggerInterface $logger = new NullLogger())
    {
    }

    public static function builder(): ServerBuilder
    {
        return new ServerBuilder();
    }

    public function run(TransportInterface $transport): void
    {
        $this->logger->info(
            'Starting MCP server with {transport} server transport.',
            ['transport' => $transport->label()],
        );

        $deferred = new DeferredFuture();

        $transport->onMessage(function (array $envelope) use ($transport): void {
            $this->dispatcher->dispatch($envelope, $transport);
        });
        $transport->onError(function (\Throwable $e): void {
            $this->logger->error('Transport error.', ['exception' => $e]);
        });
        $transport->onDrain(function (): void {
            $this->dispatcher->flushPending();
        });
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
}
