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
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles the `tools/call` request by delegating to a `ToolStoreInterface`.
 *
 * @implements RequestHandlerInterface<'tools/call', CallToolResult|InputRequiredResult, ServerContext>
 */
final readonly class CallToolRequestHandler implements RequestHandlerInterface
{
    public function __construct(private ToolStoreInterface $store, private LoggerInterface $logger = new NullLogger())
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): CallToolResult|InputRequiredResult
    {
        \assert($request instanceof CallToolRequest);
        \assert($context instanceof ServerContext);

        try {
            $result = $this->store->call($request->params->name, $request->params->arguments, $context);

            if ($result instanceof InputRequiredResult) {
                return $result;
            }

            // Spec, "Structured Content": a tool returning structured content SHOULD also return the serialized JSON in a TextContent block.
            if (null !== $result->structuredContent && [] === $result->content) {
                return new CallToolResult(
                    content: [new TextContent(text: json_encode(
                        $result->structuredContent,
                        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                    ))],
                    structuredContent: $result->structuredContent,
                    isError: $result->isError,
                    meta: $result->meta,
                );
            }

            return $result;
        } catch (AbstractJsonRpcProtocolException $e) {
            throw $e;
        } catch (ToolOutputValidationException $e) {
            $this->logger->error(
                'Tool returned structuredContent that does not conform to its outputSchema.',
                ['tool' => $request->params->name, 'exception' => $e],
            );

            return new CallToolResult(
                content: [new TextContent(text: 'Tool execution failed.')],
                isError: true,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Uncaught tool executor exception. Returning generic error to peer.',
                ['tool' => $request->params->name, 'exception' => $e],
            );

            return new CallToolResult(
                content: [new TextContent(text: 'Tool execution failed.')],
                isError: true,
            );
        }
    }
}
