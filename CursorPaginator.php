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
 * Walks a keyed entry map one page at a time.
 *
 * @internal
 */
final readonly class CursorPaginator
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @param int<1, max> $pageSize
     */
    public function __construct(private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
    }

    /**
     * @template TEntry of object
     *
     * @param array<int|non-empty-string, TEntry> $entries
     *
     * @return CursorPage<TEntry>
     *
     * @throws InvalidCursorException
     */
    public function paginate(array $entries, ?Cursor $cursor): CursorPage
    {
        $startIndex = self::resolveStartIndex($entries, $cursor);

        $page = \array_slice($entries, $startIndex, $this->pageSize, preserve_keys: true);
        $hasMore = $startIndex + \count($page) < \count($entries);
        $lastKey = array_key_last($page);

        return new CursorPage(
            array_values($page),
            $hasMore && null !== $lastKey ? new Cursor(cursor: \is_int($lastKey) ? (string) $lastKey : $lastKey) : null,
        );
    }

    /**
     * @param array<int|non-empty-string, object> $entries
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
