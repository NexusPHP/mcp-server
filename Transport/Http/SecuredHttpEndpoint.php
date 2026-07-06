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

namespace Nexus\Mcp\Server\Transport\Http;

use Nexus\Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Nexus\Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Nexus\Mcp\Server\Transport\Http\Middleware\RequestBodySizeLimitMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps an inner handler (typically the Streamable HTTP transport) with the recommended security middleware,
 * ordered CORS then DNS-rebinding protection then the optional body-size cap.
 *
 * Origin allow-listing is required. `Host` allow-listing and the body-size cap apply only when configured.
 */
final readonly class SecuredHttpEndpoint implements RequestHandlerInterface
{
    private MiddlewarePipeline $pipeline;

    /**
     * @param list<non-empty-string> $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     * @param list<non-empty-string> $allowedHosts   Hosts permitted to reach the endpoint (empty disables `Host` validation), or `['*']` to allow any
     * @param ?int                   $maxBodyBytes   Request body byte cap, or `null` to leave the body uncapped
     */
    public function __construct(
        RequestHandlerInterface $handler,
        array $allowedOrigins,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $allowedHosts = [],
        ?int $maxBodyBytes = null,
    ) {
        $middleware = [
            new CorsMiddleware($allowedOrigins, $responseFactory),
            new DnsRebindingProtectionMiddleware($allowedOrigins, $allowedHosts, $responseFactory, $streamFactory),
        ];

        if (null !== $maxBodyBytes) {
            $middleware[] = new RequestBodySizeLimitMiddleware($maxBodyBytes, $responseFactory, $streamFactory);
        }

        $this->pipeline = new MiddlewarePipeline($handler, ...$middleware);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }
}
