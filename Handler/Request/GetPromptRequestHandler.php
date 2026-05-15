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
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Prompt\PromptStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `prompts/get` request by delegating to a {@see PromptStoreInterface}.
 *
 * @implements RequestHandlerInterface<'prompts/get', GetPromptResult, ServerContext>
 */
final readonly class GetPromptRequestHandler implements RequestHandlerInterface
{
    public function __construct(private PromptStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): GetPromptResult
    {
        \assert($request instanceof GetPromptRequest);

        return $this->store->get($request->params->name, $request->params->arguments, $context);
    }
}
