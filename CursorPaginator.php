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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Server\Exception\InvalidCursorException;

/**
 * Walks a keyed entry map one page at a time, resolving each cursor to the entry that follows it.
 *
 * @internal
 */
final readonly class CursorPaginator
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @param positive-int $pageSize
     */
    public function __construct(private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
    }

    /**
     * @template TEntry of object
     *
     * @param array<array-key, TEntry> $entries
     *
     * @return CursorPage<TEntry>
     *
     * @throws InvalidCursorException
     */
    public function paginate(array $entries, ?Cursor $cursor): CursorPage
    {
        $startIndex = self::resolveStartIndex($entries, $cursor);

        // Keys are preserved because a decimal-int-string entry name is already an int key, and reindexing
        // would mint a cursor naming a position rather than an entry.
        $page = \array_slice($entries, $startIndex, $this->pageSize, preserve_keys: true);
        $hasMore = $startIndex + \count($page) < \count($entries);

        return new CursorPage(
            array_values($page),
            $hasMore ? new Cursor(cursor: (string) array_key_last($page)) : null,
        );
    }

    /**
     * @param array<array-key, object> $entries
     *
     * @return int<0, max>
     *
     * @throws InvalidCursorException
     */
    private static function resolveStartIndex(array $entries, ?Cursor $cursor): int
    {
        if (null === $cursor) {
            return 0;
        }

        $keys = array_map(strval(...), array_keys($entries));
        $offset = array_search($cursor->cursor, $keys, true);

        if (false === $offset) {
            throw new InvalidCursorException($cursor->cursor);
        }

        return $offset + 1;
    }
}
