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
 * Guards the MCP endpoint against DNS rebinding by rejecting requests from an unrecognised `Origin` or `Host`.
 *
 * A present-but-unlisted `Origin` is answered with an id-less JSON-RPC error on HTTP 403. A request without an
 * `Origin` header (non-browser clients) passes through, since only browsers send it. `Host` validation is a
 * beyond-spec, opt-in dimension: an empty allow-list disables it, otherwise the `Host` header must be present
 * and listed.
 */
final readonly class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private const string WILDCARD = '*';

    /**
     * @param list<non-empty-string> $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     * @param list<non-empty-string> $allowedHosts   Hosts permitted to reach the endpoint (empty disables `Host` validation), or `['*']` to allow any
     */
    public function __construct(
        private array $allowedOrigins,
        private array $allowedHosts,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
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

        return self::matches($request->getHeaderLine('Host'), $this->allowedHosts);
    }

    private function isOriginAllowed(ServerRequestInterface $request): bool
    {
        if (! $request->hasHeader('Origin')) {
            return true;
        }

        return self::matches($request->getHeaderLine('Origin'), $this->allowedOrigins);
    }

    /**
     * @param list<non-empty-string> $allowed
     */
    private static function matches(string $value, array $allowed): bool
    {
        return \in_array(self::WILDCARD, $allowed, true)
            || \in_array($value, $allowed, true);
    }

    private function reject(string $message): ResponseInterface
    {
        $envelope = new JsonRpcErrorResponse(
            id: null,
            error: new InvalidRequestError(message: $message),
        )->toArray();

        return $this->responseFactory->createResponse(HttpStatus::Forbidden->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
