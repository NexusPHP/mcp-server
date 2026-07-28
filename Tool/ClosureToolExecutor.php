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

use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts a closure to the `ToolExecutorInterface` contract.
 */
final readonly class ClosureToolExecutor implements ToolExecutorInterface
{
    /**
     * @param \Closure(?array<string, mixed>, ServerContext): (CallToolResult|InputRequiredResult) $closure
     */
    public function __construct(private \Closure $closure)
    {
    }

    #[\Override]
    public function execute(?array $arguments, ServerContext $context): CallToolResult|InputRequiredResult
    {
        return ($this->closure)($arguments, $context);
    }
}
