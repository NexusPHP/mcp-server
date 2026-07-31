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

use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ListChangeSourceInterface;

/**
 * A tool store whose listing can be changed after `build()`.
 */
interface MutableToolStoreInterface extends ListChangeSourceInterface, ToolStoreInterface
{
    /**
     * Registers a tool, replacing any tool already listed under the same name.
     */
    public function addTool(Tool $tool, ToolExecutorInterface $executor): void;

    /**
     * Removes the tool listed under `$name`, reporting whether one was listed.
     */
    public function removeTool(string $name): bool;
}
