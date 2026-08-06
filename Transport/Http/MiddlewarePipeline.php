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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Composes PSR-15 middleware in front of an inner request handler, typically the Streamable HTTP transport.
 *
 * The middleware run outermost-first. The pipeline is re-entrant: one instance serves concurrent requests.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware;

    public function __construct(private RequestHandlerInterface $handler, MiddlewareInterface ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ([] === $this->middleware) {
            return $this->handler->handle($request);
        }

        return $this->middleware[0]->process(
            $request,
            new self($this->handler, ...\array_slice($this->middleware, 1)),
        );
    }
}
