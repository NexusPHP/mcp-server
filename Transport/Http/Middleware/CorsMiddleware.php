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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Grants browser clients cross-origin access to the MCP endpoint.
 *
 * The middleware is additive. An allowed `Origin` is reflected into `Access-Control-Allow-Origin`, a preflight
 * `OPTIONS` is answered with `204` plus the negotiated `Access-Control-*` headers, and every other request is
 * forwarded and its response decorated. A disallowed or absent `Origin` receives no grant, so rejection stays
 * with the DNS-rebinding gate. Every response carries the `Vary` keys it turns on, grant or not, so a shared
 * cache cannot serve one origin's answer to another.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    private const string WILDCARD = '*';

    /**
     * @param list<non-empty-string> $allowedOrigins Origins granted cross-origin access, or `['*']` to allow any
     * @param int                    $maxAge         Seconds a browser may cache the preflight result
     */
    public function __construct(
        private array $allowedOrigins,
        private ResponseFactoryInterface $responseFactory,
        private int $maxAge = 600,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::isPreflight($request)) {
            return $this->preflight($request);
        }

        return $this->decorate($request, $handler->handle($request));
    }

    private function preflight(ServerRequestInterface $request): ResponseInterface
    {
        // A preflight answer turns on both request headers, so it is keyed on both whether or not the origin
        // is allowed. Without that, a cache can replay one origin's or one header set's answer to another.
        $response = $this->responseFactory->createResponse(HttpStatus::NoContent->value)
            ->withAddedHeader('Vary', 'Origin')
            ->withAddedHeader('Vary', 'Access-Control-Request-Headers')
        ;

        if (! $this->isAllowedOrigin($request)) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $request->getHeaderLine('Origin'))
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge)
        ;

        $requestedHeaders = $request->getHeaderLine('Access-Control-Request-Headers');

        return '' === $requestedHeaders
            ? $response
            : $response->withHeader('Access-Control-Allow-Headers', $requestedHeaders);
    }

    private function decorate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Whether the grant appears depends on `Origin`, so the response is keyed on it even when the origin
        // is refused. A header-free response cached without it would be replayed to an allowed origin.
        $response = $response->withAddedHeader('Vary', 'Origin');

        if (! $this->isAllowedOrigin($request)) {
            return $response;
        }

        return $response->withHeader('Access-Control-Allow-Origin', $request->getHeaderLine('Origin'));
    }

    private function isAllowedOrigin(ServerRequestInterface $request): bool
    {
        if (! $request->hasHeader('Origin')) {
            return false;
        }

        $origin = $request->getHeaderLine('Origin');

        return \in_array(self::WILDCARD, $this->allowedOrigins, true)
            || \in_array($origin, $this->allowedOrigins, true);
    }

    private static function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }
}
