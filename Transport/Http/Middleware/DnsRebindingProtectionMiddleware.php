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
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards the MCP endpoint against DNS rebinding by rejecting requests from an unrecognised `Origin`.
 * A present-but-unlisted `Origin` is answered with an id-less JSON-RPC error on HTTP 403. A request
 * without an `Origin` header (non-browser clients) passes through, since only browsers send it.
 */
final readonly class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private const string WILDCARD = '*';

    /**
     * @param list<non-empty-string> $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     */
    public function __construct(
        private array $allowedOrigins,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $request->hasHeader('Origin')) {
            return $handler->handle($request);
        }

        if ($this->isAllowed($request->getHeaderLine('Origin'))) {
            return $handler->handle($request);
        }

        return $this->reject();
    }

    private function isAllowed(string $origin): bool
    {
        return \in_array(self::WILDCARD, $this->allowedOrigins, true)
            || \in_array($origin, $this->allowedOrigins, true);
    }

    private function reject(): ResponseInterface
    {
        $envelope = new JsonRpcErrorResponse(
            id: null,
            error: new InvalidRequestError(message: 'The request Origin is not allowed.'),
        )->toArray();

        return $this->responseFactory->createResponse(HttpStatus::Forbidden->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
