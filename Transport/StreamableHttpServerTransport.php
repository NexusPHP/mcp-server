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

namespace Nexus\Mcp\Server\Transport;

use Amp\DeferredFuture;
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Core\Http\HttpStatusResolver;
use Nexus\Mcp\Core\Http\StandardHeaders;
use Nexus\Mcp\Core\JsonRpc\EnvelopeRequestId;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\CancellableTransportInterface;
use Nexus\Mcp\Core\Transport\ListenerHandle;
use Nexus\Mcp\Core\Transport\ListenerHandleInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportEvents;
use Nexus\Mcp\Core\Transport\TransportState;
use Nexus\Mcp\Server\Transport\Http\ResponseMode;
use Nexus\Mcp\Server\Transport\Http\SseResponseStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stateless Streamable HTTP server transport, and the PSR-15 request handler for the MCP endpoint.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
 */
final class StreamableHttpServerTransport implements CancellableTransportInterface, RequestHandlerInterface
{
    private const string LABEL = 'Streamable HTTP server';

    private TransportState $state = TransportState::Idle;

    /**
     * True from the first `close()` on, which `state` cannot signal as it stays `Running` across the
     * drain so a listener may still send.
     */
    private bool $closing = false;

    /**
     * @var null|\Fiber<mixed, mixed, mixed, mixed> The close owner, `null` while no close is in progress or when {main} owns it
     */
    private ?\Fiber $closingFiber = null;

    /**
     * @var null|DeferredFuture<null>
     */
    private ?DeferredFuture $closeCompletion = null;

    private readonly TransportEvents $events;

    /**
     * The last transport-internal request id minted, ascending and never reused, so a retired sink's id
     * cannot reach a later request.
     */
    private int $lastRequestId = 0;

    /**
     * In-flight requests keyed by the transport-internal id, the `buffered` deferred carrying the response
     * `handle()` awaits until `stream` is set and SSE frames take over.
     *
     * @var array<int, array{
     *   clientId: int|non-empty-string,
     *   buffered: DeferredFuture<ResponseInterface>,
     *   stream: null|SseResponseStream,
     * }>
     */
    private array $sinks = [];

    /**
     * @var array<int, \Closure(RequestId): void>
     */
    private array $cancelListeners = [];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ResponseMode $responseMode = ResponseMode::Auto,
        private readonly float $keepAliveInterval = 15.0,
    ) {
        if ($this->keepAliveInterval <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('The SSE keep-alive interval must be positive, %s given.', $this->keepAliveInterval));
        }

        $this->events = TransportEvents::create($this->logger, self::LABEL);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->responseFactory->createResponse(HttpStatus::MethodNotAllowed->value)->withHeader('Allow', 'POST');
        }

        if (TransportState::Running !== $this->state) {
            return $this->buildErrorResponse(
                new InternalError(message: 'The MCP endpoint is not accepting requests.'),
                HttpStatus::ServiceUnavailable->value,
            );
        }

        if (! self::acceptsRequiredContentTypes($request)) {
            return $this->responseFactory->createResponse(HttpStatus::NotAcceptable->value);
        }

        try {
            $envelope = json_decode((string) $request->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('{label} transport rejected a malformed JSON body.', ['label' => self::LABEL, 'exception' => $e]);
            $this->events->emitError($e);

            return $this->buildErrorResponse(new ParseError(message: ParseError::DEFAULT_MESSAGE));
        }

        try {
            Assert::that($envelope)->isMap('JSON-RPC envelope must be a JSON object, {type} given.');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('{label} transport rejected a non-object envelope.', ['label' => self::LABEL, 'exception' => $e]);
            $this->events->emitError($e);

            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        if (\array_key_exists('result', $envelope) || \array_key_exists('error', $envelope)) {
            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        if (! \array_key_exists('id', $envelope)) {
            if (! self::isAcceptableNotification($envelope)) {
                return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
            }

            if (CancelledNotification::getMethod() === ($envelope['method'] ?? null)) {
                $this->logger->debug(
                    '{label} transport ignored a client cancellation notification: the response stream is the signal on this transport.',
                    ['label' => self::LABEL],
                );

                return $this->responseFactory->createResponse(HttpStatus::Accepted->value);
            }

            $this->events->emitMessage($envelope, self::buildReceiveContext($request));

            return $this->responseFactory->createResponse(HttpStatus::Accepted->value);
        }

        $requestId = EnvelopeRequestId::recover($envelope);

        if (null === $requestId) {
            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        $clientId = $requestId->id;

        $mismatch = StandardHeaders::validate(self::readHeaders($request), $envelope);

        if (null !== $mismatch) {
            return $this->buildErrorResponse($mismatch, id: $requestId);
        }

        $streams = ResponseMode::Sse === $this->responseMode
            || SubscriptionsListenRequest::getMethod() === ($envelope['method'] ?? null);

        return $streams
            ? $this->dispatchStreaming($envelope, $clientId, $request)
            : $this->dispatchBuffered($envelope, $clientId, $request);
    }

    #[\Override]
    public function start(): void
    {
        match ($this->state) {
            TransportState::Running => throw new TransportAlreadyStartedException(transport: self::class),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'start'),
            TransportState::Idle => null,
        };

        $this->state = TransportState::Running;
        $this->logger->info('{label} transport started.', ['label' => self::LABEL]);
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        match ($this->state) {
            TransportState::Idle => throw new TransportNotStartedException(operation: 'send'),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'send'),
            TransportState::Running => null,
        };

        if ($message instanceof JsonRpcResultResponse || $message instanceof JsonRpcErrorResponse) {
            $this->routeResponse($message, $context);

            return;
        }

        if ($message instanceof JsonRpcNotification) {
            $this->routeNotification($message, $context);

            return;
        }

        $this->logger->warning('{label} transport dropped an unexpected server-initiated request.', ['label' => self::LABEL]);
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closing) {
            if (\Fiber::getCurrent() === $this->closingFiber) {
                return;
            }

            $this->closeCompletion?->getFuture()->await();

            return;
        }

        $this->closing = true;
        $this->closingFiber = \Fiber::getCurrent();

        /** @var DeferredFuture<null> $completion */
        $completion = new DeferredFuture();
        $this->closeCompletion = $completion;

        try {
            $this->events->emitDrain();
        } finally {
            $this->state = TransportState::Closed;

            try {
                try {
                    $this->retireSinks();
                } finally {
                    $this->events->emitClose();
                    $this->logger->info('{label} transport closed.', ['label' => self::LABEL]);
                }
            } finally {
                $completion->complete();
            }
        }
    }

    #[\Override]
    public function onMessage(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onMessage($listener);
    }

    #[\Override]
    public function onError(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onDrain($listener);
    }

    #[\Override]
    public function onCancel(\Closure $listener): ListenerHandleInterface
    {
        $id = spl_object_id($listener);
        $this->cancelListeners[$id] = $listener;

        return new ListenerHandle(function () use ($id): void {
            unset($this->cancelListeners[$id]);
        });
    }

    #[\Override]
    public function onClose(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onClose($listener);
    }

    /**
     * The client MUST accept both media types (`Accept: application/json, text/event-stream`) so the server
     * is free to answer with a buffered JSON object or an SSE stream.
     */
    private static function acceptsRequiredContentTypes(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        return str_contains($accept, 'application/json') && str_contains($accept, 'text/event-stream');
    }

    /**
     * A notification receives no dispatcher reply, so the transport gates its acceptance itself: a
     * well-formed JSON-RPC 2.0 notification carries a non-empty string method.
     *
     * @param array<string, mixed> $envelope
     */
    private static function isAcceptableNotification(array $envelope): bool
    {
        $method = $envelope['method'] ?? null;

        return JsonRpcMessage::JSONRPC_VERSION === ($envelope['jsonrpc'] ?? null)
            && \is_string($method)
            && '' !== $method;
    }

    /**
     * Buffers the request into a JSON response, which under `Auto` the first progress notification
     * upgrades to an SSE stream.
     *
     * @param array<string, mixed> $envelope
     * @param int|non-empty-string $clientId
     */
    private function dispatchBuffered(array $envelope, int|string $clientId, ServerRequestInterface $request): ResponseInterface
    {
        /** @var DeferredFuture<ResponseInterface> $deferred */
        $deferred = new DeferredFuture();

        $internalId = ++$this->lastRequestId;
        $this->sinks[$internalId] = ['clientId' => $clientId, 'buffered' => $deferred, 'stream' => null];

        $this->emitRequest($envelope, $internalId, self::buildReceiveContext($request, $clientId));

        return $deferred->getFuture()->await();
    }

    /**
     * Answers immediately with an SSE stream the dispatch coroutine writes progress frames and the final
     * response to.
     *
     * @param array<string, mixed> $envelope
     * @param int|non-empty-string $clientId
     */
    private function dispatchStreaming(array $envelope, int|string $clientId, ServerRequestInterface $request): ResponseInterface
    {
        /** @var DeferredFuture<ResponseInterface> $unused */
        $unused = new DeferredFuture();
        $internalId = ++$this->lastRequestId;
        $stream = new SseResponseStream($this->keepAliveInterval, function () use ($internalId): void {
            $this->releaseStream($internalId);
        });
        $response = $this->buildSseResponse($stream);
        $this->sinks[$internalId] = ['clientId' => $clientId, 'buffered' => $unused, 'stream' => $stream];

        $this->emitRequest($envelope, $internalId, self::buildReceiveContext($request, $clientId));

        return $response;
    }

    /**
     * Emits an inbound request under its transport-internal id, retiring its sink when a listener throws.
     *
     * @param array<string, mixed> $envelope
     */
    private function emitRequest(array $envelope, int $internalId, ReceiveContext $context): void
    {
        $envelope['id'] = $internalId;

        try {
            $this->events->emitMessage($envelope, $context);
        } catch (\Throwable $e) {
            unset($this->sinks[$internalId]);

            throw $e;
        }
    }

    /**
     * Retires every request still in flight at close: an open SSE stream reaches end-of-body, and a
     * buffered one gets a service-unavailable error. A sink whose error cannot be built fails with the
     * cause instead, the last of which surfaces once the rest are retired.
     */
    private function retireSinks(): void
    {
        $sinks = $this->sinks;
        $this->sinks = [];
        $failure = null;

        foreach ($sinks as $sink) {
            $stream = $sink['stream'];

            if (null !== $stream) {
                $stream->end();

                continue;
            }

            try {
                $sink['buffered']->complete($this->buildErrorResponse(
                    new InternalError(message: 'The MCP endpoint is shutting down.'),
                    status: HttpStatus::ServiceUnavailable->value,
                    id: new RequestId(id: $sink['clientId']),
                ));
            } catch (\Throwable $e) {
                $sink['buffered']->error($e);
                $failure = $e;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Carries the HTTP request, and the token a bearer-authentication stage validated it with, to handlers.
     *
     * @param null|int|non-empty-string $clientId
     */
    private static function buildReceiveContext(ServerRequestInterface $request, null|int|string $clientId = null): ReceiveContext
    {
        $token = $request->getAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE);

        return new ReceiveContext(
            $request,
            $token instanceof VerifiedAccessToken ? $token : null,
            null === $clientId ? null : new RequestId(id: $clientId),
        );
    }

    private function routeResponse(JsonRpcErrorResponse|JsonRpcResultResponse $message, ?SendContext $context): void
    {
        $id = $message->id;

        if (! $id instanceof RequestId) {
            $this->logger->warning('{label} transport discarded a response that carries no id to correlate.', ['label' => self::LABEL]);

            return;
        }

        $internalId = $id->id;

        if (! \is_int($internalId) || ! \array_key_exists($internalId, $this->sinks)) {
            $this->logger->warning('{label} transport discarded an orphan response with no in-flight request.', ['label' => self::LABEL]);

            return;
        }

        $sink = $this->sinks[$internalId];
        $envelope = $message->jsonSerialize();
        $envelope['id'] = $sink['clientId'];
        $status = self::resolveStatus($message, null !== $context && $context->fromHandler);
        $failure = null;

        try {
            $payload = self::encode($envelope);
        } catch (\JsonException $e) {
            $payload = self::encode(self::buildUnencodableError($sink['clientId']));
            $status = HttpStatus::InternalServerError->value;
            $failure = $e;
        }

        $this->deliver($sink, $internalId, $payload, $status);

        if (null !== $failure) {
            $this->logger->error('{label} transport replaced a response JSON cannot encode with an internal error: {reason}.', ['label' => self::LABEL, 'reason' => $failure->getMessage()]);
        }
    }

    /**
     * Writes the payload to its request's sink, retiring it first so nothing else can settle the request.
     * A response that cannot be built fails the request rather than leaving it parked.
     *
     * @param array{clientId: int|non-empty-string, buffered: DeferredFuture<ResponseInterface>, stream: null|SseResponseStream} $sink
     */
    private function deliver(array $sink, int $internalId, string $payload, int $status): void
    {
        $stream = $sink['stream'];
        unset($this->sinks[$internalId]);

        if (null !== $stream) {
            $stream->push(self::frame($payload));
            $stream->end();
        } else {
            try {
                $response = $this->buildJsonResponse($payload, $status);
            } catch (\Throwable $e) {
                $sink['buffered']->error($e);

                throw $e;
            }

            $sink['buffered']->complete($response);
        }
    }

    /**
     * The error standing in for a response JSON cannot encode, echoing the client's own id.
     *
     * @param int|non-empty-string $clientId
     *
     * @return array<string, mixed>
     */
    private static function buildUnencodableError(int|string $clientId): array
    {
        return (new JsonRpcErrorResponse(
            id: new RequestId(id: $clientId),
            error: new InternalError(message: 'The response could not be encoded.'),
        ))->jsonSerialize();
    }

    /**
     * @param JsonRpcNotification<non-empty-string> $notification
     */
    private function routeNotification(JsonRpcNotification $notification, ?SendContext $context): void
    {
        $related = $context?->relatedRequestId;

        if (! $related instanceof RequestId) {
            $this->logger->debug('{label} transport dropped a notification with no related request to stream it to.', ['label' => self::LABEL]);

            return;
        }

        $internalId = $related->id;

        if (! \is_int($internalId) || ! \array_key_exists($internalId, $this->sinks)) {
            $this->logger->debug('{label} transport dropped a notification for a request that is no longer in flight.', ['label' => self::LABEL]);

            return;
        }

        $sink = $this->sinks[$internalId];
        $stream = $sink['stream'];

        if (null !== $stream) {
            $stream->push(self::frame(self::encode($notification->jsonSerialize())));
        } elseif (ResponseMode::Auto === $this->responseMode) {
            $this->upgradeToStream($internalId, $sink['clientId'], $sink['buffered'], $notification->jsonSerialize());
        } else {
            $this->logger->debug('{label} transport dropped a notification: the JSON response mode cannot stream it.', ['label' => self::LABEL]);
        }
    }

    /**
     * Lazily promotes a buffered `Auto` request to an SSE stream, standing down when the request was
     * settled while its response was being built.
     *
     * @param int|non-empty-string              $clientId
     * @param DeferredFuture<ResponseInterface> $buffered
     * @param array<string, mixed>              $envelope
     */
    private function upgradeToStream(int $internalId, int|string $clientId, DeferredFuture $buffered, array $envelope): void
    {
        $frame = self::frame(self::encode($envelope));
        $stream = new SseResponseStream($this->keepAliveInterval, function () use ($internalId): void {
            $this->releaseStream($internalId);
        });
        $response = $this->buildSseResponse($stream);

        if (! \array_key_exists($internalId, $this->sinks)) {
            return;
        }

        $this->sinks[$internalId] = ['clientId' => $clientId, 'buffered' => $buffered, 'stream' => $stream];

        $stream->push($frame);
        $buffered->complete($response);
    }

    /**
     * Retires a stream whose body the consumer closed (a client disconnect), and a no-op once it has ended.
     */
    private function releaseStream(int $internalId): void
    {
        $sink = $this->sinks[$internalId] ?? null;
        unset($this->sinks[$internalId]);

        if (null === $sink) {
            return;
        }

        $abandoned = new RequestId(id: $internalId);

        foreach ($this->cancelListeners as $listener) {
            $listener($abandoned);
        }
    }

    /**
     * A result and a handler-produced error both ride HTTP 200 with the JSON-RPC payload in the body,
     * while a protocol error carries a real status.
     */
    private static function resolveStatus(JsonRpcErrorResponse|JsonRpcResultResponse $message, bool $fromHandler): int
    {
        if ($message instanceof JsonRpcResultResponse) {
            return HttpStatus::Ok->value;
        }

        return HttpStatusResolver::resolve($message->error->code, $fromHandler);
    }

    private function buildJsonResponse(string $payload, int $status): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload))
        ;
    }

    private function buildSseResponse(SseResponseStream $body): ResponseInterface
    {
        return $this->responseFactory->createResponse(HttpStatus::Ok->value)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($body)
        ;
    }

    /**
     * @param null|int $status The HTTP status to pin, or `null` to derive it from the error's code
     */
    private function buildErrorResponse(Error $error, ?int $status = null, ?RequestId $id = null): ResponseInterface
    {
        $status ??= HttpStatusResolver::resolve($error->code, fromHandler: false);
        $envelope = (new JsonRpcErrorResponse(id: $id, error: $error))->jsonSerialize();

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(self::encode($envelope)))
        ;
    }

    /**
     * @return array<string, string>
     */
    private static function readHeaders(ServerRequestInterface $request): array
    {
        return array_map(
            static fn(array $values): string => implode(', ', $values),
            $request->getHeaders(),
        );
    }

    private static function frame(string $payload): string
    {
        return \sprintf("event: message\ndata: %s\n\n", $payload);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function encode(array $envelope): string
    {
        return json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
