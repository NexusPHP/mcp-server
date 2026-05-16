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

/**
 * Pairs a `Tool` with the executor that runs its invocations.
 *
 * @internal
 */
final readonly class ToolEntry
{
    public function __construct(public Tool $tool, public ToolExecutorInterface $executor)
    {
    }
}
