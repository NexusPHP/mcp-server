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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles the `tools/call` request by delegating to a `ToolStoreInterface`.
 *
 * @implements RequestHandlerInterface<'tools/call', CallToolResult, ServerContext>
 */
final readonly class CallToolRequestHandler implements RequestHandlerInterface
{
    public function __construct(private ToolStoreInterface $store, private LoggerInterface $logger = new NullLogger())
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): CallToolResult
    {
        \assert($request instanceof CallToolRequest);

        try {
            return $this->store->call($request->params->name, $request->params->arguments, $context);
        } catch (AbstractJsonRpcProtocolException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Generic peer-facing text. Raw $e->getMessage() can carry paths or secrets.
            $this->logger->error(
                'Uncaught tool executor exception. Returning generic error to peer.',
                ['tool' => $request->params->name, 'exception' => $e],
            );

            return new CallToolResult(
                content: [new TextContent('Tool execution failed.')],
                isError: true,
            );
        }
    }
}
