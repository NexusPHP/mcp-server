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
use Amp\NullCancellation;
use Nexus\Mcp\Core\Dispatch\MessageDispatcherInterface;
use Nexus\Mcp\Core\Dispatch\PendingCoroutines;
use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Dispatch\RequestBoundSender;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\DuplicateInboundRequestIdException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
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

/**
 * Server-side per-envelope inbound dispatch. Parses, classifies, gates, resolves a handler,
 * spawns a coroutine to run it, and sends the response (or error) on the transport.
 */
final readonly class ServerMessageDispatcher implements MessageDispatcherInterface
{
    private PendingCoroutines $coroutines;
    private PendingInboundRequests $inboundRequests;

    /**
     * @param HandlerRegistry<RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param HandlerRegistry<NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    public function __construct(
        private HandlerRegistry $requestHandlers,
        private HandlerRegistry $notificationHandlers,
        private ServerInitializationGate $initializationGate,
        private LoggingLevelGate $loggingLevelGate = new LoggingLevelGate(),
        private LoggerInterface $logger = new NullLogger(),
        private JsonRpcMessageParser $parser = new JsonRpcMessageParser(),
        private Cancellation $cancellation = new NullCancellation(),
    ) {
        $this->coroutines = new PendingCoroutines();
        $this->inboundRequests = new PendingInboundRequests();
    }

    #[\Override]
    public function flushPending(): void
    {
        $this->coroutines->flushPending();
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[\Override]
    public function dispatch(array $envelope, TransportInterface $transport): void
    {
        if (\array_key_exists('result', $envelope) || \array_key_exists('error', $envelope)) {
            $this->discardResponseEnvelope($envelope);

            return;
        }

        $isNotification = ! \array_key_exists('id', $envelope);

        try {
            $message = $this->parser->parse($envelope);
        } catch (MethodMisroutedException $e) {
            $this->logger->warning(
                'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.',
                ['envelope' => $envelope, 'exception' => $e],
            );

            if (! $isNotification) {
                // Envelope carried an id but the method is a notification method.
                // JSON-RPC 2.0 §4.1 forbids responses to notifications. Drop silently.
                return;
            }

            // Envelope omitted the id but the method is a request method.
            // §5 null-id fallback. Respond so the peer can fix the malformed request.
            $this->sendResponse($transport, self::toErrorResponse($e, null), 'misrouted');

            return;
        } catch (AbstractJsonRpcProtocolException $e) {
            if ($isNotification) {
                $this->logger->info(
                    'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
                    ['envelope' => $envelope, 'exception' => $e],
                );

                return;
            }

            $this->sendResponse($transport, self::toErrorResponse($e, null), 'parse-error');

            return;
        }

        if ($message instanceof JsonRpcRequest) {
            $this->dispatchRequest($message, $transport);
        } elseif ($message instanceof JsonRpcNotification) {
            $this->dispatchNotification($message);
        }
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
        $method = $request::method();
        $isInitializeRequest = InitializeRequest::method() === $method;

        // Gate is mutated sync so a same-tick `notifications/initialized` sees `InitializeInFlight`.
        if (! $this->initializationGate->allowsRequest($method)) {
            $exception = $isInitializeRequest
                ? new ServerAlreadyInitializedException($request->id)
                : new ServerNotInitializedException($method, $request->id);

            $this->sendResponse($transport, self::toErrorResponse($exception, $request->id), $method);

            return;
        }

        if (! $this->inboundRequests->claim($request->id)) {
            $exception = new DuplicateInboundRequestIdException($request->id);
            $this->sendResponse($transport, self::toErrorResponse($exception, $request->id), $method);

            return;
        }

        if ($isInitializeRequest) {
            $this->initializationGate->markInitializeInFlight();
        }

        $this->coroutines->track(async(function () use ($request, $transport, $method, $isInitializeRequest): void {
            try {
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
                } catch (TransportAlreadyClosedException $e) {
                    if ($isInitializeRequest) {
                        $this->initializationGate->revertInitializeInFlight();
                    }

                    $this->logSkippedDelivery($method, $e);

                    return;
                } catch (AbstractJsonRpcProtocolException $e) {
                    if ($isInitializeRequest) {
                        $this->initializationGate->revertInitializeInFlight();
                    }

                    $this->sendResponse($transport, self::toErrorResponse($e, $request->id), $method);

                    return;
                } catch (\Throwable $e) {
                    if ($isInitializeRequest) {
                        $this->initializationGate->revertInitializeInFlight();
                    }

                    $this->logger->error(
                        'Uncaught request handler exception.',
                        ['method' => $method, 'exception' => $e],
                    );
                    $this->sendResponse($transport, new JsonRpcErrorResponse(
                        $request->id,
                        new InternalError(),
                    ), $method);

                    return;
                }

                if ($isInitializeRequest) {
                    $this->initializationGate->markInitializeCompleted();
                }

                $this->sendResponse($transport, new JsonRpcResultResponse($request->id, $result), $method);
            } finally {
                $this->inboundRequests->release($request->id);
            }
        }));
    }

    /**
     * Sends a response and demotes the predictable "peer hung up" failure to an info log.
     */
    private function sendResponse(TransportInterface $transport, JsonRpcMessage $message, string $method): void
    {
        try {
            $transport->send($message);
        } catch (TransportAlreadyClosedException $e) {
            $this->logSkippedDelivery($method, $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to deliver response to transport.',
                ['method' => $method, 'exception' => $e],
            );
        }
    }

    private function logSkippedDelivery(string $method, TransportAlreadyClosedException $exception): void
    {
        $this->logger->info(
            'Skipping response delivery. Transport is closed.',
            ['method' => $method, 'exception' => $exception],
        );
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
                    'Discarding "notifications/initialized" received in an unexpected initialize handshake state.',
                    ['method' => $method],
                );

                return;
            }
        } elseif (! $this->initializationGate->isInitialized()) {
            // Other notifications must wait for a complete handshake. The initialized arm above
            // intentionally falls through even when buffered (gate may still be InitializeInFlight).
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

        $this->coroutines->track(async(function () use ($handler, $notification, $method): void {
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

    private static function toErrorResponse(
        AbstractJsonRpcProtocolException $exception,
        ?RequestId $fallbackId,
    ): JsonRpcErrorResponse {
        return new JsonRpcErrorResponse(
            $exception->requestId ?? $fallbackId,
            ErrorFactory::create($exception::errorCode(), $exception->getMessage()),
        );
    }
}
