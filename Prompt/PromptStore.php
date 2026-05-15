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

namespace Nexus\Mcp\Server\Prompt;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of {@see PromptStoreInterface}.
 *
 * @phpstan-type PromptEntry array{prompt: Prompt, renderer: \Closure(?array<string, string>, ServerContext): GetPromptResult}
 */
final readonly class PromptStore implements PromptStoreInterface
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, PromptEntry> $entries
     */
    public function __construct(private array $entries = [], private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        Assert::that($this->entries)
            ->keys()
            ->isNonEmptyString('Prompt store entry key must be a non-empty string.')
        ;
        Assert::that($this->pageSize)
            ->isPositiveInt('Prompt store page size must be a positive integer, {value} given.')
        ;

        $this->keyIndex = array_flip(array_keys($this->entries));
    }

    #[\Override]
    public function list(?Cursor $cursor): ListPromptsResult
    {
        $startIndex = $this->startIndexFor($cursor);
        $page = \array_slice($this->entries, $startIndex, $this->pageSize);
        $prompts = array_values(array_map(static fn(array $entry): Prompt => $entry['prompt'], $page));

        $hasMore = $startIndex + \count($page) < \count($this->entries);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return new ListPromptsResult($prompts, $nextCursor);
    }

    #[\Override]
    public function get(string $name, ?array $arguments, ServerContext $context): GetPromptResult
    {
        if (! \array_key_exists($name, $this->entries)) {
            throw new PromptNotFoundException($name, $context->requestId);
        }

        return ($this->entries[$name]['renderer'])($arguments, $context);
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
