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
            return new CallToolResult([new TextContent($result)]);
        }

        if ($result instanceof ContentBlock) {
            return new CallToolResult(self::contentBlocks([$result]));
        }

        if (\is_array($result)) {
            return self::structuredOrContent($result);
        }

        throw new UnsupportedReturnValueException(
            $this->method->getDeclaringClass()->getName(),
            $this->method->getName(),
            \sprintf('a %s, a string, content blocks, or an array', CallToolResult::class),
            $result,
        );
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private static function structuredOrContent(array $result): CallToolResult
    {
        if (array_is_list($result) && [] !== $result) {
            $blocks = self::contentBlocks($result);

            if (\count($blocks) === \count($result)) {
                return new CallToolResult($blocks);
            }
        }

        Assert::that($result)->isMap('Tool structured content must be a string-keyed object.');

        return new CallToolResult([], $result);
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent>
     */
    private static function contentBlocks(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn(mixed $item): bool => $item instanceof AudioContent
                || $item instanceof EmbeddedResource
                || $item instanceof ImageContent
                || $item instanceof ResourceLink
                || $item instanceof TextContent,
        ));
    }
}
