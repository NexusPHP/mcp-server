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
 * Grants browser clients cross-origin access to the MCP endpoint additively, leaving rejection to the
 * DNS-rebinding gate.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
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
        if ($this->isPreflight($request)) {
            return $this->answerPreflight($request);
        }

        return $this->decorate($request, $handler->handle($request));
    }

    private function answerPreflight(ServerRequestInterface $request): ResponseInterface
    {
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

        return \in_array('*', $this->allowedOrigins, true) || \in_array($origin, $this->allowedOrigins, true);
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }
}
