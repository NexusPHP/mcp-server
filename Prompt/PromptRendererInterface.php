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
use Nexus\Mcp\Server\ServerContext;

/**
 * Renders a single prompt when invoked via `prompts/get`.
 */
interface PromptRendererInterface
{
    /**
     * @param null|array<string, string> $arguments
     */
    public function render(?array $arguments, ServerContext $context): GetPromptResult;
}
