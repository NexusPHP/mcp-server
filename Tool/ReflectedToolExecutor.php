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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
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
    public function execute(?array $arguments, ServerContext $context): CallToolResult
    {
        $bound = $this->binder->bind($this->method, $arguments ?? [], $context);

        return $this->adapt($this->method->invokeArgs($this->handler, $bound));
    }

    private function adapt(mixed $result): CallToolResult
    {
        if ($result instanceof CallToolResult) {
            return $result;
        }

        if (\is_string($result)) {
            return new CallToolResult(content: [new TextContent(text: $result)]);
        }

        if ($result instanceof ContentBlock) {
            return new CallToolResult(content: self::contentBlocks([$result]));
        }

        if (\is_array($result)) {
            return self::structuredOrContent($result, $this->method);
        }

        throw self::buildUnsupportedError($this->method, $result);
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private static function structuredOrContent(array $result, \ReflectionMethod $method): CallToolResult
    {
        if (array_is_list($result) && [] !== $result) {
            $blocks = self::contentBlocks($result);

            if (\count($blocks) !== \count($result)) {
                throw self::buildUnsupportedError($method, $result);
            }

            return new CallToolResult(content: $blocks);
        }

        try {
            Assert::that($result)->isMap('Tool structured content must be a string-keyed object.');
        } catch (ExpectationFailedException) {
            throw self::buildUnsupportedError($method, $result);
        }

        return new CallToolResult(content: [], structuredContent: $result);
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent>
     */
    private static function contentBlocks(array $items): array
    {
        $blocks = [];

        foreach ($items as $item) {
            if ($item instanceof AudioContent
                || $item instanceof EmbeddedResource
                || $item instanceof ImageContent
                || $item instanceof ResourceLink
                || $item instanceof TextContent
            ) {
                $blocks[] = $item;
            }
        }

        return $blocks;
    }

    private static function buildUnsupportedError(\ReflectionMethod $method, mixed $result): UnsupportedReturnValueException
    {
        return new UnsupportedReturnValueException(
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            \sprintf('a %s, a string, content blocks, or an array', CallToolResult::class),
            $result,
        );
    }
}
