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
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;

/**
 * Handles the `tools/list` request by delegating to a {@see ToolStoreInterface}.
 *
 * @implements RequestHandlerInterface<'tools/list', ListToolsResult, ServerContext>
 */
final readonly class ListToolsRequestHandler implements RequestHandlerInterface
{
    public function __construct(private ToolStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): ListToolsResult
    {
        \assert($request instanceof ListToolsRequest);

        return $this->store->list($request->params->cursor);
    }
}
