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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of {@see ToolStoreInterface}.
 *
 * @phpstan-type ToolEntry array{tool: Tool, executor: \Closure(?array<string, mixed>, ServerContext): CallToolResult}
 */
final readonly class ToolStore implements ToolStoreInterface
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, ToolEntry> $entries
     */
    public function __construct(private array $entries = [], private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        Assert::that($this->entries)
            ->keys()
            ->isNonEmptyString('Tool store entry key must be a non-empty string.')
        ;
        Assert::that($this->pageSize)
            ->isPositiveInt('Tool store page size must be a positive integer, {value} given.')
        ;

        $this->keyIndex = array_flip(array_keys($this->entries));
    }

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        $startIndex = $this->startIndexFor($cursor);
        $page = \array_slice($this->entries, $startIndex, $this->pageSize);
        $tools = array_values(array_map(static fn(array $entry): Tool => $entry['tool'], $page));

        $hasMore = $startIndex + \count($page) < \count($this->entries);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return new ListToolsResult($tools, $nextCursor);
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult
    {
        if (! \array_key_exists($name, $this->entries)) {
            throw new ToolNotFoundException($name, $context->requestId);
        }

        return ($this->entries[$name]['executor'])($arguments, $context);
    }

    private function startIndexFor(?Cursor $cursor): int
    {
        if (null === $cursor) {
            return 0;
        }

        $raw = $cursor->cursor;

        if (! isset($this->keyIndex[$raw])) {
            throw new InvalidCursorException($raw);
        }

        return $this->keyIndex[$raw] + 1;
    }
}
