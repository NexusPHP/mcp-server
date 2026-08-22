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
 * Wraps an inner handler (typically the Streamable HTTP transport) with the recommended security middleware.
 */
final readonly class SecuredHttpEndpoint implements RequestHandlerInterface
{
    public const int DEFAULT_MAX_BODY_BYTES = 1_048_576;

    private MiddlewarePipeline $pipeline;

    /**
     * @param list<non-empty-string>   $allowedOrigins Origins permitted to reach the endpoint, or `['*']` to allow any
     * @param list<non-empty-string>   $allowedHosts   Hosts permitted to reach the endpoint (empty disables `Host` validation), or `['*']` to allow any
     * @param null|int<0, max>         $maxBodyBytes   Request body bytes past which the request is refused, or `null` for no cap
     * @param null|ToolStoreInterface  $toolStore      The served tool store, enabling `Mcp-Param-{Name}` validation
     * @param null|MiddlewareInterface $authentication Bearer token enforcement, making the endpoint an OAuth resource server
     */
    public function __construct(
        RequestHandlerInterface $handler,
        array $allowedOrigins,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $allowedHosts = [],
        ?int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        ?ToolStoreInterface $toolStore = null,
        LoggerInterface $logger = new NullLogger(),
        ?MiddlewareInterface $authentication = null,
    ) {
        $middleware = [
            new CorsMiddleware($allowedOrigins, $responseFactory),
            new DnsRebindingProtectionMiddleware($allowedOrigins, $allowedHosts, $responseFactory, $streamFactory),
        ];

        if (null !== $authentication) {
            $middleware[] = $authentication;
        }

        if (null !== $maxBodyBytes) {
            $middleware[] = new RequestBodySizeLimitMiddleware($maxBodyBytes, $responseFactory, $streamFactory);
        }

        if (null !== $toolStore) {
            $middleware[] = new ParameterHeaderValidationMiddleware($toolStore, $responseFactory, $streamFactory, $logger);
        }

        $this->pipeline = new MiddlewarePipeline($handler, ...$middleware);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }
}
