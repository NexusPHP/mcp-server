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
use Amp\DeferredFuture;
use Nexus\Mcp\Core\Dispatch\LogThrottle;
use Nexus\Mcp\Core\Dispatch\MessageDispatcherInterface;
use Nexus\Mcp\Core\Dispatch\PendingCoroutines;
use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Dispatch\RequestBoundSender;
use Nexus\Mcp\Core\Dispatch\ResponseSender;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\DuplicateInboundRequestIdException;
use Nexus\Mcp\Core\Exception\InvalidRequestException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\JsonRpc\ResultResponseFactory;
use Nexus\Mcp\Core\SafeDisplay;
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
use Nexus\Mcp\Core\Schema\RequestParams\InputResponseCarrierInterface;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * Server-side per-envelope inbound dispatch.
 *
 * @internal
 */
final readonly class ServerMessageDispatcher implements MessageDispatcherInterface
{
    private const string OVERLOADED_MESSAGE = 'Server overloaded';

    private PendingCoroutines $coroutines;

    /**
     * Start signal per served listen.
     */
    private PendingCoroutines $unstartedListens;

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
        $this->unstartedListens = new PendingCoroutines($this->logger);
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
                ['exception' => $e],
            );

            // §4.1 splits notification from request on the envelope's id, not on the method it names, and §5 obliges a reply echoing that id.
            if ($isNotification) {
                return;
            }

            $this->responseSender->send($transport, ResponseSender::buildErrorResponse($e, null), 'misrouted');

            return;
        } catch (AbstractJsonRpcProtocolException $e) {
            if ($isNotification) {
                $this->logger->info(
                    'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
                    ['exception' => $e],
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

    private function isSaturated(): bool
    {
        return null !== $this->maxInFlight && $this->maxInFlight <= $this->coroutines->count();
    }

    private function isListenSaturated(): bool
    {
        return null !== $this->maxInFlight && $this->maxInFlight <= $this->unstartedListens->count();
    }

    /**
     * @param JsonRpcRequest<non-empty-string> $request
     */
    private function dispatchRequest(JsonRpcRequest $request, TransportInterface $transport, ReceiveContext $context): void
    {
        $method = $request::getMethod();
        $holdsOpen = SubscriptionsListenRequest::getMethod() === $method && $this->requestHandlers->get($method) !== null;

        if ($holdsOpen ? $this->isListenSaturated() : $this->isSaturated()) {
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

        $started = null;

        if ($holdsOpen) {
            /** @var DeferredFuture<null> $started */
            $started = new DeferredFuture();
            $this->unstartedListens->track($started->getFuture());
        }

        $this->coroutines->track(async(function () use ($request, $transport, $method, $context, $cancellation, $started): void {
            $started?->complete();

            try {
                try {
                    if (! $request instanceof ClientRequest) {
                        throw new MethodNotFoundException($method, $request->id);
                    }

                    if (! $request->params instanceof RequestParams) {
                        throw new InvalidRequestException($request->id, '"params" must be an object carrying the lifecycle "_meta" fields.');
                    }

                    $requestedVersion = $request->params->meta->protocolVersion->version;

                    if (! \in_array($requestedVersion, ProtocolVersion::SUPPORTED_VERSIONS, true)) {
                        $this->responseSender->send($transport, new JsonRpcErrorResponse(
                            id: $request->id,
                            error: new UnsupportedProtocolVersionError(
                                requested: SafeDisplay::sanitise($requestedVersion),
                                supported: ProtocolVersion::SUPPORTED_VERSIONS,
                            ),
                        ), $method);

                        return;
                    }

                    $handler = $this->requestHandlers->get($method)
                        ?? throw new MethodNotFoundException($method, $request->id);

                    $sender = new RequestBoundSender($transport, $request->id);
                    $params = $request->params;
                    $carrier = $params instanceof InputResponseCarrierInterface ? $params : null;
                    $serverContext = new ServerContext(
                        $request->id,
                        $cancellation,
                        $params->meta,
                        $sender,
                        $context,
                        $carrier?->getInputResponses(),
                        $carrier?->getRequestState(),
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
                    $this->responseSender->send($transport, ResponseSender::buildErrorResponse($e, $request->id), $method);

                    return;
                } catch (AbstractJsonRpcProtocolException $e) {
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
     * Sends `$response` unless the peer abandoned the request first, which the spec forbids answering.
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
                // JSON-RPC 2.0 §4.1 gives a notification no response, so failure is only logged.
                $this->logger->error(
                    'Uncaught notification handler exception.',
                    ['method' => $method, 'exception' => $e],
                );
            }
        }));
    }
}
