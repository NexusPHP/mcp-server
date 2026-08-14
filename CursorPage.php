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

/**
 * One page of store entries, with the cursor that fetches the page after it.
 *
 * @template-covariant TEntry of object
 *
 * @internal
 */
final readonly class CursorPage
{
    /**
     * @param list<TEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?Cursor $nextCursor,
    ) {
    }
}
