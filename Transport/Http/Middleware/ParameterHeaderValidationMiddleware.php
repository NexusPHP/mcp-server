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
use Nexus\Mcp\Core\JsonRpc\EnvelopeRequestId;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\ListChangeSourceInterface;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
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
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#server-behavior-for-custom-headers
 */
final class ParameterHeaderValidationMiddleware implements MiddlewareInterface
{
    /**
     * Bindings keyed by tool name, or `null` until the store has been scanned.
     *
     * @var null|array<string, list<ParameterHeaderBinding>>
     */
    private ?array $bindings = null;

    private readonly ParameterHeaders $parameterHeaders;

    public function __construct(
        private readonly ToolStoreInterface $store,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->parameterHeaders = new ParameterHeaders();

        if ($store instanceof ListChangeSourceInterface) {
            $store->onListChanged(function (): void {
                $this->bindings = null;
            });
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (string) $request->getBody();
        $request = $request->withBody($this->streamFactory->createStream($body));
        $envelope = json_decode($body, associative: true);

        if (! \is_array($envelope)) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute(StreamableHttpServerTransport::ENVELOPE_ATTRIBUTE, $envelope);

        if (CallToolRequest::getMethod() !== ($envelope['method'] ?? null)) {
            return $handler->handle($request);
        }

        $params = $envelope['params'] ?? null;

        if (! \is_array($params)) {
            return $handler->handle($request);
        }

        $name = $params['name'] ?? null;

        if (! \is_string($name)) {
            return $handler->handle($request);
        }

        $arguments = $params['arguments'] ?? [];
        $mismatch = $this->parameterHeaders->validate(
            $this->resolveBindings($name),
            \is_array($arguments) ? $arguments : [],
            $this->readHeaders($request),
        );

        if (null === $mismatch) {
            return $handler->handle($request);
        }

        return $this->reject($mismatch, EnvelopeRequestId::recover($envelope));
    }

    /**
     * @return list<ParameterHeaderBinding>
     */
    private function resolveBindings(string $tool): array
    {
        $this->bindings ??= $this->scan();

        return $this->bindings[$tool] ?? [];
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
     * @return array<string, string>
     */
    private function readHeaders(ServerRequestInterface $request): array
    {
        return array_map(
            static fn(array $values): string => implode(', ', $values),
            $request->getHeaders(),
        );
    }

    private function reject(HeaderMismatchError $error, ?RequestId $id): ResponseInterface
    {
        $envelope = new JsonRpcErrorResponse(id: $id, error: $error);

        return $this->responseFactory->createResponse(HttpStatus::BadRequest->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
