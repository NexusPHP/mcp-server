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

use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts a closure to the `PromptRendererInterface` contract.
 */
final readonly class ClosurePromptRenderer implements PromptRendererInterface
{
    /**
     * @param \Closure(?array<string, string>, ServerContext): (GetPromptResult|InputRequiredResult) $closure
     */
    public function __construct(private \Closure $closure)
    {
    }

    #[\Override]
    public function render(?array $arguments, ServerContext $context): GetPromptResult|InputRequiredResult
    {
        return ($this->closure)($arguments, $context);
    }
}
