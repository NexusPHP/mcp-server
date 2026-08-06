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

use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Nexus\Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Nexus\Mcp\Server\Transport\Http\Middleware\ParameterHeaderValidationMiddleware;
use Nexus\Mcp\Server\Transport\Http\Middleware\RequestBodySizeLimitMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wraps an inner handler (typically the Streamable HTTP transport) with the recommended security middleware,
 * ordered CORS, then DNS-rebinding protection, then `Mcp-Param-{Name}` validation, then the optional
 * body-size cap.
 *
 * Origin allow-listing is required. `Host` allow-listing, bearer authentication, parameter-header validation,
 * and the body-size cap apply only when configured. A server whose tools declare `x-mcp-header` must pass its
 * tool store so the spec-required header-to-body validation runs.
 */
final readonly class SecuredHttpEndpoint implements RequestHandlerInterface
{
    private MiddlewarePipeline $pipeline;

    /**
     * @param list<non-empty-string>   $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     * @param list<non-empty-string>   $allowedHosts   Hosts permitted to reach the endpoint (empty disables `Host` validation), or `['*']` to allow any
     * @param null|int<0, max>         $maxBodyBytes   Request body byte cap, or `null` to leave the body uncapped
     * @param null|ToolStoreInterface  $toolStore      The served tool store, enabling `Mcp-Param-{Name}` validation
     * @param null|MiddlewareInterface $authentication Bearer token enforcement, making the endpoint an OAuth resource server
     */
    public function __construct(
        RequestHandlerInterface $handler,
        array $allowedOrigins,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $allowedHosts = [],
        ?int $maxBodyBytes = null,
        ?ToolStoreInterface $toolStore = null,
        LoggerInterface $logger = new NullLogger(),
        ?MiddlewareInterface $authentication = null,
    ) {
        $middleware = [
            new CorsMiddleware($allowedOrigins, $responseFactory),
            new DnsRebindingProtectionMiddleware($allowedOrigins, $allowedHosts, $responseFactory, $streamFactory),
        ];

        // Authentication runs before anything reads the body, so an unauthorized request is turned away
        // without it being parsed.
        if (null !== $authentication) {
            $middleware[] = $authentication;
        }

        if (null !== $toolStore) {
            $middleware[] = new ParameterHeaderValidationMiddleware($toolStore, $responseFactory, $streamFactory, $logger);
        }

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
