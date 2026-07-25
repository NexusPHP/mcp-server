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

namespace Nexus\Mcp\Server\Transport\Http\Middleware;

use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Core\Http\ParameterHeaderBinding;
use Nexus\Mcp\Core\Http\ParameterHeaders;
use Nexus\Mcp\Core\Http\ParameterHeaderScanner;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Rejects a `tools/call` whose `Mcp-Param-{Name}` headers disagree with the arguments in its body.
 *
 * The spec requires any server that processes the body to validate the mirrored headers against it, so an
 * intermediary routing on a header value cannot disagree with what the server executes. Bindings are read
 * from the tool `inputSchema` declarations once and cached, which a `ToolStoreInterface` whose tool set
 * changes after the first `tools/call` would outlive.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http#server-behavior-for-custom-headers
 */
final class ParameterHeaderValidationMiddleware implements MiddlewareInterface
{
    /**
     * Bindings keyed by tool name, or `null` until the store has been scanned.
     *
     * @var null|array<string, list<ParameterHeaderBinding>>
     */
    private ?array $bindings = null;

    public function __construct(
        private readonly ToolStoreInterface $store,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $envelope = self::readEnvelope($request);

        if (CallToolRequest::getMethod() !== ($envelope['method'] ?? null)) {
            // Only a tool call mirrors arguments into headers. Every other method, malformed bodies included,
            // is the transport's to answer, even when it carries a `params.name` of its own.
            return $handler->handle($request);
        }

        $params = $envelope['params'] ?? null;
        $name = \is_array($params) ? $params['name'] ?? null : null;

        if (! \is_string($name)) {
            return $handler->handle($request);
        }

        $arguments = \is_array($params) ? $params['arguments'] ?? [] : [];
        $mismatch = ParameterHeaders::validate(
            $this->bindingsFor($name),
            \is_array($arguments) ? array_filter($arguments, is_string(...), \ARRAY_FILTER_USE_KEY) : [],
            self::readHeaders($request),
        );

        if (null === $mismatch) {
            return $handler->handle($request);
        }

        return $this->reject($mismatch, $envelope['id'] ?? null);
    }

    /**
     * @return list<ParameterHeaderBinding>
     */
    private function bindingsFor(string $tool): array
    {
        return ($this->bindings ??= $this->scan())[$tool] ?? [];
    }

    /**
     * @return array<string, list<ParameterHeaderBinding>>
     */
    private function scan(): array
    {
        $bindings = [];
        $cursor = null;

        do {
            $page = $this->store->list($cursor);

            foreach ($page->tools as $tool) {
                $result = ParameterHeaderScanner::scan($tool->inputSchema);

                if (! $result->valid) {
                    // An invalid scan yields no bindings, so the tool goes unvalidated. A conforming client
                    // already excluded it from its own listing and will never call it.
                    $this->logger->warning(
                        'Skipping {tool} header validation: its "x-mcp-header" declarations are invalid.',
                        ['tool' => $tool->name, 'reason' => $result->reason],
                    );
                }

                $bindings[$tool->name] = $result->bindings;
            }

            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $bindings;
    }

    /**
     * Peeks at the request body. PSR-7 has `__toString()` seek to the start, so the transport downstream
     * still reads a whole body. An int-keyed decode yields no envelope keys and so governs nothing.
     *
     * @return array<string, mixed>
     */
    private static function readEnvelope(ServerRequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), associative: true);

        return \is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
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

    private function reject(HeaderMismatchError $error, mixed $id): ResponseInterface
    {
        $requestId = \is_int($id) || (\is_string($id) && '' !== $id) ? new RequestId(id: $id) : null;
        $envelope = new JsonRpcErrorResponse(id: $requestId, error: $error)->toArray();

        return $this->responseFactory->createResponse(HttpStatus::BadRequest->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
