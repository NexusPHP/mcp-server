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
use Nexus\Mcp\Core\Dispatch\LogThrottle;
use Nexus\Mcp\Core\Dispatch\MessageDispatcherInterface;
use Nexus\Mcp\Core\Dispatch\PendingCoroutines;
use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Dispatch\RequestBoundSender;
use Nexus\Mcp\Core\Dispatch\ResponseSender;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\DuplicateInboundRequestIdException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\JsonRpc\ResultResponseFactory;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\Error\UnsupportedProtocolVersionError;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\InputResponseRequestParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * Server-side per-envelope inbound dispatch. Parses, classifies, resolves a handler,
 * spawns a coroutine to run it, and sends the response (or error) on the transport.
 *
 * @internal
 */
final readonly class ServerMessageDispatcher implements MessageDispatcherInterface
{
    private const string OVERLOADED_MESSAGE = 'Server overloaded';

    private PendingCoroutines $coroutines;
    private ResponseSender $responseSender;
    private LogThrottle $orphanResponses;
    private LogThrottle $shedNotifications;

    /**
     * @param HandlerRegistry<RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param HandlerRegistry<NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     * @param null|Implementation                                                               $serverInfo           Identity stamped on every outgoing result, or null to disclose none
     * @param null|int<1, max>                                                                  $maxInFlight          Messages processed at once before further ones are shed, or null for no cap
     */
    public function __construct(
        private HandlerRegistry $requestHandlers,
        private HandlerRegistry $notificationHandlers,
        private LoggerInterface $logger = new NullLogger(),
        private JsonRpcMessageParser $parser = new JsonRpcMessageParser(),
        private ?Implementation $serverInfo = null,
        private ?int $maxInFlight = null,
        private PendingInboundRequests $inboundRequests = new PendingInboundRequests(),
    ) {
        $this->coroutines = new PendingCoroutines($this->logger);
        $this->responseSender = new ResponseSender($this->logger);
        $this->orphanResponses = new LogThrottle();
        $this->shedNotifications = new LogThrottle();
    }

    #[\Override]
    public function flushPending(): void
    {
        $this->coroutines->flushPending();
    }

    #[\Override]
    public function cancelRequest(RequestId $id): void
    {
        $this->inboundRequests->cancel($id);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[\Override]
    public function dispatch(array $envelope, TransportInterface $transport, ReceiveContext $context): void
    {
        if (\array_key_exists('result', $envelope) || \array_key_exists('error', $envelope)) {
            $this->discardResponseEnvelope();

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

            // §4.1 defines a notification as an envelope *without* an id, so one that carries an id is a
            // request whatever method it names and §5 obliges a reply. Echo the id the envelope supplied,
            // falling back to the §5 null id when it supplied none.
            $this->responseSender->send($transport, ResponseSender::buildErrorResponse($e, $e->requestId), 'misrouted');

            return;
        } catch (AbstractJsonRpcProtocolException $e) {
            if ($isNotification) {
                $this->logger->info(
                    'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
                    ['envelope' => $envelope, 'exception' => $e],
                );

                return;
            }

            $this->responseSender->send($transport, ResponseSender::buildErrorResponse($e, null), 'parse-error');

            return;
        }

        if ($message instanceof JsonRpcRequest) {
            $this->dispatchRequest($message, $transport, $context);
        } elseif ($message instanceof JsonRpcNotification) {
            $this->dispatchNotification($message);
        }
    }

    /**
     * Reports a discarded response envelope without echoing its payload.
     */
    private function discardResponseEnvelope(): void
    {
        if (! $this->orphanResponses->admits()) {
            return;
        }

        $this->logger->warning(
            'Discarded {count} response envelope(s) so far (server has no outbound-request correlation).',
            ['count' => $this->orphanResponses->count()],
        );
    }

    /**
     * Whether the dispatcher is already running as many messages as it accepts at once.
     */
    private function isSaturated(): bool
    {
        return null !== $this->maxInFlight && $this->maxInFlight <= $this->coroutines->count();
    }

    /**
     * @param JsonRpcRequest<non-empty-string> $request
     */
    private function dispatchRequest(JsonRpcRequest $request, TransportInterface $transport, ReceiveContext $context): void
    {
        $method = $request::getMethod();

        if ($this->isSaturated()) {
            // Shed before claiming the id, so a shed request leaves no trace a retry would collide with.
            $this->responseSender->send($transport, new JsonRpcErrorResponse(
                id: $request->id,
                error: new UnknownProtocolError(
                    code: SdkErrorCode::Overloaded->value,
                    message: self::OVERLOADED_MESSAGE,
                ),
            ), $method);

            return;
        }

        $cancellation = $this->inboundRequests->claim($request->id);

        if (null === $cancellation) {
            $exception = new DuplicateInboundRequestIdException($request->id);
            $this->responseSender->send($transport, ResponseSender::buildErrorResponse($exception, $request->id), $method);

            return;
        }

        // A listen request opens a subscription rather than being processed, so the dispatch cap does not
        // govern it. `SubscriptionStoreInterface` bounds how many streams may be open.
        $holdsOpen = SubscriptionsListenRequest::getMethod() === $method;

        $this->coroutines->track(async(function () use ($request, $transport, $method, $context, $cancellation): void {
            try {
                try {
                    if (! $request instanceof ClientRequest) {
                        // The server services only ClientRequest methods. A server-to-client method is
                        // one the server does not implement, so it answers MethodNotFound.
                        throw new MethodNotFoundException($method, $request->id);
                    }

                    // A ClientRequest always carries the heavy RequestParams (the required _meta).
                    \assert($request->params instanceof RequestParams);

                    $requestedVersion = $request->params->meta->protocolVersion->version;

                    if (! \in_array($requestedVersion, ProtocolVersion::SUPPORTED_VERSIONS, true)) {
                        $this->responseSender->send($transport, new JsonRpcErrorResponse(
                            id: $request->id,
                            error: new UnsupportedProtocolVersionError(
                                requested: $requestedVersion,
                                supported: ProtocolVersion::SUPPORTED_VERSIONS,
                            ),
                        ), $method);

                        return;
                    }

                    $handler = $this->requestHandlers->get($method)
                        ?? throw new MethodNotFoundException($method, $request->id);

                    $sender = new RequestBoundSender($transport, $request->id);
                    $params = $request->params;
                    $serverContext = new ServerContext(
                        $request->id,
                        $cancellation,
                        $params->meta,
                        $sender,
                        $context,
                        $params instanceof InputResponseRequestParams ? $params->inputResponses : null,
                        $params instanceof InputResponseRequestParams ? $params->requestState : null,
                    );
                    $result = $handler->handle($request, $serverContext);

                    if (null !== $this->serverInfo && ! $result->meta->declaresServerInfo()) {
                        $result = $result->rebuildWithMeta(new GenericResultMetaObject(
                            serverInfo: $this->serverInfo,
                            extras: $result->meta->extras,
                        ));
                    }

                    $response = ResultResponseFactory::wrap($request, $result);
                } catch (TransportAlreadyClosedException $e) {
                    $this->responseSender->logSkippedDelivery($method, $e);

                    return;
                } catch (MethodNotFoundException $e) {
                    // A routing miss the framework raises: no handler serves the method.
                    $this->responseSender->send($transport, ResponseSender::buildErrorResponse($e, $request->id), $method);

                    return;
                } catch (AbstractJsonRpcProtocolException $e) {
                    // A protocol error the handler itself raised (e.g. invalid tool arguments).
                    $this->sendUnlessCancelled(
                        $cancellation,
                        $transport,
                        ResponseSender::buildErrorResponse($e, $request->id),
                        $method,
                        new SendContext(fromHandler: true),
                    );

                    return;
                } catch (\Throwable $e) {
                    if ($cancellation->isRequested()) {
                        $this->logger->debug(
                            'Dropping the response to a request whose handler the cancellation interrupted.',
                            ['method' => $method],
                        );

                        return;
                    }

                    // A `CancelledException` from a token the handler armed itself is not this request being
                    // cancelled, so the peer is still owed an answer.
                    $this->logger->error(
                        'Uncaught request handler exception.',
                        ['method' => $method, 'exception' => $e],
                    );
                    $this->responseSender->send(
                        $transport,
                        new JsonRpcErrorResponse(
                            id: $request->id,
                            error: new InternalError(message: InternalError::DEFAULT_MESSAGE),
                        ),
                        $method,
                        new SendContext(fromHandler: true),
                    );

                    return;
                }

                $this->sendUnlessCancelled($cancellation, $transport, $response, $method);
            } finally {
                $this->inboundRequests->release($request->id);
            }
        }), occupiesSlot: ! $holdsOpen);
    }

    /**
     * Sends `$response` unless the peer abandoned the request first. The spec forbids answering a request
     * once its cancellation was requested.
     *
     * @param non-empty-string $method
     */
    private function sendUnlessCancelled(
        Cancellation $cancellation,
        TransportInterface $transport,
        JsonRpcErrorResponse|JsonRpcResultResponse $response,
        string $method,
        ?SendContext $context = null,
    ): void {
        if ($cancellation->isRequested()) {
            $this->logger->debug('Dropping the response to a cancelled request.', ['method' => $method]);

            return;
        }

        $this->responseSender->send($transport, $response, $method, $context);
    }

    /**
     * @param JsonRpcNotification<non-empty-string> $notification
     */
    private function dispatchNotification(JsonRpcNotification $notification): void
    {
        $method = $notification::getMethod();

        $handler = $this->notificationHandlers->get($method);

        if (null === $handler) {
            return;
        }

        if ($this->isSaturated()) {
            // JSON-RPC 2.0 §4.1 forbids answering a notification, so a shed one is dropped outright.
            if ($this->shedNotifications->admits()) {
                $this->logger->warning(
                    'Shed {count} notification(s) so far. The server is at its in-flight dispatch cap.',
                    ['count' => $this->shedNotifications->count()],
                );
            }

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
}
