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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\CancellableTransportInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Server\Subscription\SubscriptionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin shell that drives a single transport's lifecycle.
 */
final readonly class Server
{
    public function __construct(
        private MessageDispatcherInterface $dispatcher,
        private LoggerInterface $logger = new NullLogger(),
        private ?SubscriptionStoreInterface $subscriptions = null,
    ) {
    }

    /**
     * Runs the server on the transport, blocking until it closes, for a long-lived
     * transport that owns its read loop (stdio).
     */
    public function run(TransportInterface $transport): void
    {
        $this->logger->info('Starting MCP server.');

        $deferred = new DeferredFuture();

        $this->attachDispatchListeners($transport);

        $transport->onClose(static function () use ($deferred): void {
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
     * Attaches the dispatcher and starts the transport without blocking, for a
     * request-scoped transport (streamable HTTP in a PSR-15 stack) the host drives per request.
     */
    public function listen(TransportInterface $transport): void
    {
        $this->attachDispatchListeners($transport);

        $transport->start();
    }

    private function attachDispatchListeners(TransportInterface $transport): void
    {
        $this->subscriptions?->reopen();

        $transport->onMessage(function (array $envelope, ReceiveContext $context) use ($transport): void {
            $this->dispatcher->dispatch($envelope, $transport, $context);
        });
        $transport->onError(function (\Throwable $e): void {
            $this->logger->error('Transport error.', ['exception' => $e]);
        });
        $transport->onDrain(function (): void {
            $this->subscriptions?->closeAll();
            $this->dispatcher->flushPending();
        });

        if ($transport instanceof CancellableTransportInterface) {
            $transport->onCancel(function (RequestId $id): void {
                $this->dispatcher->cancelRequest($id);
            });
        }
    }
}
