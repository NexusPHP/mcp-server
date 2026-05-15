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
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Server\Prompt\PromptStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `prompts/list` request by delegating to a `PromptStoreInterface`.
 *
 * @implements RequestHandlerInterface<'prompts/list', ListPromptsResult, ServerContext>
 */
final readonly class ListPromptsRequestHandler implements RequestHandlerInterface
{
    public function __construct(private PromptStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): ListPromptsResult
    {
        \assert($request instanceof ListPromptsRequest);

        return $this->store->list($request->params->cursor);
    }
}
