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
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Server\Resource\ResourceTemplateStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `resources/templates/list` request by delegating to a {@see ResourceTemplateStoreInterface}.
 *
 * @implements RequestHandlerInterface<'resources/templates/list', ListResourceTemplatesResult, ServerContext>
 */
final readonly class ListResourceTemplatesRequestHandler implements RequestHandlerInterface
{
    public function __construct(private ResourceTemplateStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): ListResourceTemplatesResult
    {
        \assert($request instanceof ListResourceTemplatesRequest);

        return $this->store->listTemplates($request->params->cursor);
    }
}
