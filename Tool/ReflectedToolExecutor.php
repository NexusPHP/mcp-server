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

namespace Nexus\Mcp\Server\Tool;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\Discovery\ArgumentBinder;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts an attribute-discovered handler method to the `ToolExecutorInterface` contract.
 */
final readonly class ReflectedToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private object $handler,
        private \ReflectionMethod $method,
        private ArgumentBinder $binder = new ArgumentBinder(),
    ) {
    }

    #[\Override]
    public function execute(?array $arguments, ServerContext $context): CallToolResult|InputRequiredResult
    {
        $bound = $this->binder->bind($this->method, $arguments ?? [], $context);

        return $this->adapt($this->method->invokeArgs($this->handler, $bound));
    }

    private function adapt(mixed $result): CallToolResult|InputRequiredResult
    {
        if ($result instanceof CallToolResult || $result instanceof InputRequiredResult) {
            return $result;
        }

        return match (true) {
            \is_string($result) => new CallToolResult(content: [new TextContent(text: $result)]),
            $result instanceof AudioContent,
            $result instanceof EmbeddedResource,
            $result instanceof ImageContent,
            $result instanceof ResourceLink,
            $result instanceof TextContent => new CallToolResult(content: [$result]),
            \is_array($result) => $this->resolveStructuredOrContent($result, $this->method),
            default => throw $this->buildUnsupportedError($this->method, $result),
        };
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private function resolveStructuredOrContent(array $result, \ReflectionMethod $method): CallToolResult
    {
        if (array_is_list($result) && [] !== $result) {
            $blocks = [];

            foreach ($result as $item) {
                if ($item instanceof AudioContent
                    || $item instanceof EmbeddedResource
                    || $item instanceof ImageContent
                    || $item instanceof ResourceLink
                    || $item instanceof TextContent
                ) {
                    $blocks[] = $item;
                }
            }

            if (\count($blocks) !== \count($result)) {
                throw $this->buildUnsupportedError($method, $result);
            }

            return new CallToolResult(content: $blocks);
        }

        try {
            Assert::that($result)->isMap('Tool structured content must be a string-keyed object.');
        } catch (\InvalidArgumentException) {
            throw $this->buildUnsupportedError($method, $result);
        }

        return new CallToolResult(content: [], structuredContent: $result);
    }

    private function buildUnsupportedError(\ReflectionMethod $method, mixed $result): UnsupportedReturnValueException
    {
        return new UnsupportedReturnValueException(
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            \sprintf('a %s, a string, content blocks, or an array', CallToolResult::class),
            $result,
        );
    }
}
