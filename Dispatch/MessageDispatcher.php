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

namespace Nexus\Mcp\Server\Dispatch;

use Amp\Cancellation;
use Amp\Future;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Server\Exception\ServerAlreadyInitializedException;
use Nexus\Mcp\Server\Exception\ServerNotInitializedException;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * Per-envelope inbound dispatch. Parses, classifies, gates, resolves a handler,
 * spawns a coroutine to run it, and sends the response (or error) on the transport.
 */
final readonly class MessageDispatcher
{
    /**
     * @var \SplObjectStorage<Future<mixed>, null>
     */
    private \SplObjectStorage $pending;

    /**
     * @param HandlerRegistry<RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param HandlerRegistry<NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    public function __construct(
        private HandlerRegistry $requestHandlers,
        private HandlerRegistry $notificationHandlers,
        private InitializationGate $initializationGate,
        private LoggingLevelGate $loggingLevelGate = new LoggingLevelGate(),
        private LoggerInterface $logger = new NullLogger(),
        private JsonRpcMessageParser $parser = new JsonRpcMessageParser(),
        private Cancellation $cancellation = new NullCancellation(),
    ) {
        $this->pending = new \SplObjectStorage();
    }

    /**
     * Awaits every in-flight dispatch coroutine.
     */
    public function flushPending(): void
    {
        awaitAll($this->pending);
    }

    /**
     * Number of dispatch coroutines currently in flight.
     */
    public function inFlightCount(): int
    {
        return \count($this->pending);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function dispatch(array $envelope, TransportInterface $transport): void
    {
        $isNotification = ! \array_key_exists('id', $envelope);
        $isResponseShape = \array_key_exists('result', $envelope) || \array_key_exists('error', $envelope);

        try {
            $message = $this->parser->parse($envelope);
        } catch (AbstractJsonRpcProtocolException $e) {
            if ($isResponseShape) {
                $this->discardResponseEnvelope($envelope);

                return;
            }

            if ($isNotification) {
                $this->logger->info(
                    'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
                    ['envelope' => $envelope, 'exception' => $e],
                );

                return;
            }

            $transport->send(self::toErrorResponse($e, null));

            return;
        }

        match (true) {
            $message instanceof JsonRpcRequest => $this->dispatchRequest($message, $transport),
            $message instanceof JsonRpcNotification => $this->dispatchNotification($message),
            default => $this->discardResponseEnvelope($envelope),
        };
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function discardResponseEnvelope(array $envelope): void
    {
        $this->logger->warning(
            'Discarding response envelope (server has no outbound-request correlation).',
            ['envelope' => $envelope],
        );
    }

    /**
     * @param JsonRpcRequest<non-empty-string> $request
     */
    private function dispatchRequest(JsonRpcRequest $request, TransportInterface $transport): void
    {
        $this->track(async(function () use ($request, $transport): void {
            $method = $request::method();

            try {
                if (! $this->initializationGate->allowsRequest($method)) {
                    $exception = InitializeRequest::method() === $method
                        ? new ServerAlreadyInitializedException($request->id)
                        : new ServerNotInitializedException($method, $request->id);

                    $transport->send(self::toErrorResponse($exception, $request->id));

                    return;
                }

                $sender = new RequestBoundSender($transport, $request->id);
                $context = new ServerContext(
                    $request->id,
                    $this->cancellation,
                    $request->params->meta,
                    $transport->sessionId(),
                    $sender,
                    $this->loggingLevelGate,
                );

                try {
                    $handler = $this->requestHandlers->get($method)
                        ?? throw new MethodNotFoundException($method, $request->id);
                    $result = $handler->handle($request, $context);

                    if (InitializeRequest::method() === $method) {
                        $this->initializationGate->markInitializeInFlight();
                    }

                    $transport->send(new JsonRpcResultResponse($request->id, $result));
                } catch (AbstractJsonRpcProtocolException $e) {
                    $transport->send(self::toErrorResponse($e, $request->id));
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Uncaught request handler exception.',
                        ['method' => $method, 'exception' => $e],
                    );
                    $transport->send(new JsonRpcErrorResponse(
                        $request->id,
                        new InternalError(),
                    ));
                }
            } catch (\Throwable $e) {
                // Outer catch: a transport `send()` inside an inner catch-arm could itself throw.
                // Without this guard the coroutine future ends in an unhandled error.
                $this->logger->error(
                    'Failed to deliver response to transport.',
                    ['method' => $method, 'exception' => $e],
                );
            }
        }));
    }

    /**
     * @param JsonRpcNotification<non-empty-string> $notification
     */
    private function dispatchNotification(JsonRpcNotification $notification): void
    {
        $method = $notification::method();

        if (InitializedNotification::method() === $method) {
            if (! $this->initializationGate->markInitialized()) {
                $this->logger->warning(
                    'Discarding "notifications/initialized" received outside of an in-flight "initialize" handshake.',
                    ['method' => $method],
                );

                return;
            }
        }

        if (! $this->initializationGate->isInitialized()) {
            $this->logger->info(
                'Dropping notification before client has completed initialize.',
                ['method' => $method],
            );

            return;
        }

        $handler = $this->notificationHandlers->get($method);

        if (null === $handler) {
            return;
        }

        $this->track(async(function () use ($handler, $notification, $method): void {
            try {
                $handler->handle($notification);
            } catch (\Throwable $e) {
                // Notifications carry no response per JSON-RPC 2.0 §4.1. Failure is logged only.
                $this->logger->error(
                    'Uncaught notification handler exception.',
                    ['method' => $method, 'exception' => $e],
                );
            }
        }));
    }

    /**
     * @param Future<mixed> $future
     */
    private function track(Future $future): void
    {
        $this->pending[$future] = null;

        $future->finally(function () use ($future): void {
            unset($this->pending[$future]);
        });
    }

    private static function toErrorResponse(
        AbstractJsonRpcProtocolException $exception,
        ?RequestId $fallbackId,
    ): JsonRpcErrorResponse {
        return new JsonRpcErrorResponse(
            $exception->requestId ?? $fallbackId,
            Error::forCode($exception::errorCode(), $exception->getMessage()),
        );
    }
}
