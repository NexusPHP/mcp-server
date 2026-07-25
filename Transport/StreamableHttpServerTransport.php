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
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Core\Http\HttpStatusResolver;
use Nexus\Mcp\Core\Http\StandardHeaders;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Core\Transport\TransportEvents;
use Nexus\Mcp\Core\Transport\TransportInterface;
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
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http
 */
final class StreamableHttpServerTransport implements RequestHandlerInterface, TransportInterface
{
    private TransportState $state = TransportState::Idle;
    private readonly TransportEvents $events;

    /**
     * The last transport-internal request id minted. Ids ascend and are never reused for the process's
     * lifetime, so a retired sink's id cannot reach a later request while the handler that still holds it
     * runs on.
     */
    private int $lastRequestId = 0;

    /**
     * In-flight requests, keyed by the transport-internal id emitted to the dispatcher. On the buffered path
     * the `buffered` deferred carries the response `handle()` awaits. Once `stream` is set the sink streams
     * SSE frames instead and the deferred goes unused.
     *
     * @var array<int, array{
     *   clientId: int|non-empty-string,
     *   buffered: DeferredFuture<ResponseInterface>,
     *   stream: ?SseResponseStream,
     * }>
     */
    private array $sinks = [];

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

        $this->events = new TransportEvents();
    }

    /**
     * Handles one HTTP request against the MCP endpoint, returning the response to write back.
     */
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->responseFactory->createResponse(HttpStatus::MethodNotAllowed->value)->withHeader('Allow', 'POST');
        }

        if (TransportState::Running !== $this->state) {
            // Nothing is listening, so no dispatch would ever resolve the request. Fail fast rather than
            // suspending on a response that cannot arrive.
            return $this->buildErrorResponse(
                new InternalError(message: 'The MCP endpoint is not accepting requests.'),
                HttpStatus::ServiceUnavailable->value,
            );
        }

        if (! self::acceptsRequiredContentTypes($request)) {
            // The client must accept both media types so the server may answer with JSON or an SSE stream.
            return $this->responseFactory->createResponse(HttpStatus::NotAcceptable->value);
        }

        try {
            $envelope = json_decode((string) $request->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // An empty or otherwise undecodable body.
            return $this->buildErrorResponse(new ParseError(message: ParseError::DEFAULT_MESSAGE));
        }

        try {
            Assert::that($envelope)->isMap('JSON-RPC envelope must be a JSON object, {type} given.');
        } catch (\InvalidArgumentException) {
            // Valid JSON that is not an object (a scalar, or a JSON array such as a removed batch).
            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        if (\array_key_exists('result', $envelope) || \array_key_exists('error', $envelope)) {
            // The body must be a request or notification. A response is not a valid client-to-server message,
            // and the dispatcher discards responses without replying, so admitting one would hang the POST.
            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        if (! \array_key_exists('id', $envelope)) {
            if (! self::isAcceptableNotification($envelope)) {
                // The server cannot accept a malformed notification, so it answers an HTTP error, not 202.
                return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
            }

            $this->events->emitMessage($envelope, new ReceiveContext($request));

            return $this->responseFactory->createResponse(HttpStatus::Accepted->value);
        }

        $clientId = $envelope['id'];

        if (! \is_int($clientId) && ! (\is_string($clientId) && '' !== $clientId)) {
            // MCP narrows the request id to int|non-empty-string.
            return $this->buildErrorResponse(new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE));
        }

        $mismatch = StandardHeaders::validate(self::readHeaders($request), $envelope);

        if (null !== $mismatch) {
            return $this->buildErrorResponse($mismatch);
        }

        return ResponseMode::Sse === $this->responseMode
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

        // The server issues no client-bound requests over Streamable HTTP.
        $this->logger->warning('Dropping an unexpected server-initiated request.');
    }

    #[\Override]
    public function close(): void
    {
        if (TransportState::Closed === $this->state) {
            return;
        }

        // Draining lets any in-flight dispatch coroutine send its response and resolve the awaiting request
        // before the transport is marked closed.
        try {
            $this->events->emitDrain();
        } finally {
            $this->state = TransportState::Closed;
            $this->events->emitClose();
        }
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onMessage($listener);
    }

    #[\Override]
    public function onError(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): SubscriptionInterface
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
     * Buffers the request, resolving a JSON response when the final response arrives. Under `Auto`, the
     * first progress notification upgrades the awaited response to an SSE stream instead.
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

        $envelope['id'] = $internalId;
        $this->events->emitMessage($envelope, new ReceiveContext($request));

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
        // The streaming response is returned directly rather than through the sink's deferred, which only an
        // `Auto` upgrade from the buffered path ever completes.
        /** @var DeferredFuture<ResponseInterface> $unused */
        $unused = new DeferredFuture();
        $internalId = ++$this->lastRequestId;
        $stream = new SseResponseStream($this->keepAliveInterval, fn(): null => $this->releaseStream($internalId));
        $this->sinks[$internalId] = ['clientId' => $clientId, 'buffered' => $unused, 'stream' => $stream];

        $envelope['id'] = $internalId;
        $this->events->emitMessage($envelope, new ReceiveContext($request));

        return $this->buildSseResponse($stream);
    }

    private function routeResponse(JsonRpcErrorResponse|JsonRpcResultResponse $message, ?SendContext $context): void
    {
        $id = $message->id;

        if (! $id instanceof RequestId) {
            $this->logger->warning('Discarding a response that carries no id to correlate.');

            return;
        }

        $internalId = $id->id;

        if (! \is_int($internalId) || ! \array_key_exists($internalId, $this->sinks)) {
            $this->logger->warning('Discarding an orphan response with no in-flight request.');

            return;
        }

        $sink = $this->sinks[$internalId];
        $envelope = $message->toArray();
        $envelope['id'] = $sink['clientId'];
        $stream = $sink['stream'];

        if (null !== $stream) {
            $stream->push(self::frame($envelope));
            $this->endStream($internalId, $stream);
        } else {
            $fromHandler = null !== $context && $context->fromHandler;
            $sink['buffered']->complete($this->buildJsonResponse($envelope, self::resolveStatus($message, $fromHandler)));
            unset($this->sinks[$internalId]);
        }
    }

    /**
     * @param JsonRpcNotification<non-empty-string> $notification
     */
    private function routeNotification(JsonRpcNotification $notification, ?SendContext $context): void
    {
        $related = $context?->relatedRequestId;

        if (! $related instanceof RequestId) {
            $this->logger->debug('Dropping a notification with no related request to stream it to.');

            return;
        }

        $internalId = $related->id;

        if (! \is_int($internalId) || ! \array_key_exists($internalId, $this->sinks)) {
            $this->logger->debug('Dropping a notification for a request that is no longer in flight.');

            return;
        }

        $sink = $this->sinks[$internalId];
        $stream = $sink['stream'];

        if (null !== $stream) {
            $stream->push(self::frame($notification->toArray()));
        } elseif (ResponseMode::Auto === $this->responseMode) {
            $this->upgradeToStream($internalId, $sink['clientId'], $sink['buffered'], $notification->toArray());
        } else {
            // The JSON response mode buffers a single object and has no stream to carry a notification.
            $this->logger->debug('Dropping a notification: the JSON response mode cannot stream it.');
        }
    }

    /**
     * Lazily promotes a buffered `Auto` request to an SSE stream, replaying the progress notification that
     * triggered the upgrade and resolving the awaiting `handle()` with the streaming response.
     *
     * @param int|non-empty-string              $clientId
     * @param DeferredFuture<ResponseInterface> $buffered
     * @param array<string, mixed>              $envelope
     */
    private function upgradeToStream(int $internalId, int|string $clientId, DeferredFuture $buffered, array $envelope): void
    {
        $stream = new SseResponseStream($this->keepAliveInterval, fn(): null => $this->releaseStream($internalId));
        $this->sinks[$internalId] = ['clientId' => $clientId, 'buffered' => $buffered, 'stream' => $stream];

        $stream->push(self::frame($envelope));
        $buffered->complete($this->buildSseResponse($stream));
    }

    /**
     * Ends a stream after its final response frame: signals end-of-body to the reader and retires the sink.
     */
    private function endStream(int $internalId, SseResponseStream $stream): void
    {
        $stream->end();
        unset($this->sinks[$internalId]);
    }

    /**
     * Retires a stream whose body the consumer closed (a client disconnect); a no-op once it has ended.
     */
    private function releaseStream(int $internalId): null
    {
        unset($this->sinks[$internalId]);

        return null;
    }

    /**
     * A result rides HTTP 200. An error's status turns on its origin: a handler-produced error rides 200
     * with the JSON-RPC error in the body, while a protocol error carries a real status.
     */
    private static function resolveStatus(JsonRpcErrorResponse|JsonRpcResultResponse $message, bool $fromHandler): int
    {
        if ($message instanceof JsonRpcResultResponse) {
            return HttpStatus::Ok->value;
        }

        // A consumer may send an error whose code falls outside the spec-defined set, so the code is
        // resolved leniently: throwing here would strand the request that is awaiting this response.
        return HttpStatusResolver::resolve(ProtocolErrorCode::tryFrom($message->error->code), $fromHandler);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function buildJsonResponse(array $envelope, int $status): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(self::encode($envelope)))
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
     * @param ?int $status The HTTP status to pin, or `null` to derive it from the error's code
     */
    private function buildErrorResponse(Error $error, ?int $status = null): ResponseInterface
    {
        // A transport-synthesised error always carries a spec-defined code, so it maps to a ProtocolErrorCode.
        $status ??= HttpStatusResolver::resolve(ProtocolErrorCode::from($error->code), fromHandler: false);
        $envelope = new JsonRpcErrorResponse(id: null, error: $error)->toArray();

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(self::encode($envelope)))
        ;
    }

    /**
     * Flattens the PSR-7 header bag to the single-value-per-name map the header validator consumes.
     *
     * @return array<string, string>
     */
    private static function readHeaders(ServerRequestInterface $request): array
    {
        return array_map(
            static fn(array $values): string => implode(', ', $values),
            $request->getHeaders(),
        );
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function frame(array $envelope): string
    {
        return \sprintf("event: message\ndata: %s\n\n", self::encode($envelope));
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function encode(array $envelope): string
    {
        return json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
