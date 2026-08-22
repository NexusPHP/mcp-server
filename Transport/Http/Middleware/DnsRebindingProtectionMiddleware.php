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
 * Guards the MCP endpoint against DNS rebinding by rejecting an unlisted `Origin` with an id-less JSON-RPC
 * error on HTTP 403.
 */
final readonly class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private const string WILDCARD = '*';

    /**
     * @var list<non-empty-string>
     */
    private array $allowedOrigins;

    /**
     * @var list<non-empty-string>
     */
    private array $allowedHosts;

    /**
     * @param list<non-empty-string> $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     * @param list<non-empty-string> $allowedHosts   Hosts permitted to reach the endpoint (empty disables `Host` validation), or `['*']` to allow any
     */
    public function __construct(
        array $allowedOrigins,
        array $allowedHosts,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
        $this->allowedOrigins = array_map(strtolower(...), $allowedOrigins);
        $this->allowedHosts = array_map(strtolower(...), $allowedHosts);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->isHostAllowed($request)) {
            return $this->reject('The request Host is not allowed.');
        }

        if (! $this->isOriginAllowed($request)) {
            return $this->reject('The request Origin is not allowed.');
        }

        return $handler->handle($request);
    }

    private function isHostAllowed(ServerRequestInterface $request): bool
    {
        if ([] === $this->allowedHosts) {
            return true;
        }

        return $this->matches($request->getHeaderLine('Host'), $this->allowedHosts);
    }

    private function isOriginAllowed(ServerRequestInterface $request): bool
    {
        if (! $request->hasHeader('Origin')) {
            return true;
        }

        return $this->matches($request->getHeaderLine('Origin'), $this->allowedOrigins);
    }

    /**
     * @param list<non-empty-string> $allowed
     */
    private function matches(string $value, array $allowed): bool
    {
        return \in_array(self::WILDCARD, $allowed, true)
            || \in_array(strtolower($value), $allowed, true);
    }

    /**
     * @param non-empty-string $message
     */
    private function reject(string $message): ResponseInterface
    {
        $envelope = new JsonRpcErrorResponse(
            id: null,
            error: new InvalidRequestError(message: $message),
        );

        return $this->responseFactory->createResponse(HttpStatus::Forbidden->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
