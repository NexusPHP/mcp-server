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

/**
 * A store whose listing can change after `build()`, and which reports each change to its listeners.
 *
 * The store names no notification type. Which `notifications/*\/list_changed` a change becomes is fixed
 * by the feature the listener registered on.
 */
interface ListChangeSourceInterface
{
    /**
     * Registers a listener invoked once per change to the entries this store lists.
     *
     * @param \Closure(): void $listener
     */
    public function onListChanged(\Closure $listener): void;
}
