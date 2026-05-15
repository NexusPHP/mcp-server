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

namespace Nexus\Mcp\Server\Handler\Request;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Server\Resource\ResourceStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `resources/list` request by delegating to a `ResourceStoreInterface`.
 *
 * @implements RequestHandlerInterface<'resources/list', ListResourcesResult, ServerContext>
 */
final readonly class ListResourcesRequestHandler implements RequestHandlerInterface
{
    public function __construct(private ResourceStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): ListResourcesResult
    {
        \assert($request instanceof ListResourcesRequest);

        return $this->store->list($request->params->cursor);
    }
}
