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

namespace Nexus\Mcp\Server;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Server\Exception\InvalidCursorException;

/**
 * Shared cursor scaffolding for in-memory per-feature stores.
 *
 * @template TEntry of object
 */
abstract readonly class AbstractPaginatedStore
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * Subclass override. Used as the prefix in constructor-time assert messages.
     */
    protected const string STORE_LABEL = 'Store';

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, TEntry> $entries
     */
    public function __construct(
        protected array $entries = [],
        protected int $pageSize = self::DEFAULT_PAGE_SIZE,
        protected int $ttlMs = 0,
        protected CacheScope $cacheScope = CacheScope::Private,
    ) {
        Assert::that($entries)
            ->keys()
            ->isNonEmptyString(\sprintf('%s entry key must be a non-empty string.', static::STORE_LABEL))
        ;
        Assert::that($pageSize)
            ->isPositiveInt(\sprintf('%s page size must be a positive integer, {value} given.', static::STORE_LABEL))
        ;
        Assert::that($ttlMs)
            ->isNaturalInt(\sprintf('%s TTL must be a non-negative integer, {value} given.', static::STORE_LABEL))
        ;

        $this->keyIndex = array_flip(array_keys($entries));
    }

    /**
     * @template TItem of object
     * @template TResult of object
     *
     * @param \Closure(TEntry): TItem                                  $transform
     * @param \Closure(list<TItem>, ?Cursor, int, CacheScope): TResult $resultBuilder
     *
     * @return TResult
     *
     * @throws InvalidCursorException
     */
    final protected function paginate(?Cursor $cursor, \Closure $transform, \Closure $resultBuilder): object
    {
        $startIndex = $this->resolveStartIndex($cursor);
        $page = \array_slice($this->entries, $startIndex, $this->pageSize);
        $items = array_values(array_map($transform, $page));

        $hasMore = $startIndex + \count($page) < \count($this->entries);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return $resultBuilder($items, $nextCursor, $this->ttlMs, $this->cacheScope);
    }

    private function resolveStartIndex(?Cursor $cursor): int
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
