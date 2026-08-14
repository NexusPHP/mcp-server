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

use Nexus\Mcp\Core\Schema\Prompt\Prompt;

/**
 * Pairs a `Prompt` with the renderer that resolves it for a request.
 */
final readonly class PromptEntry
{
    public function __construct(
        public Prompt $prompt,
        public PromptRendererInterface $renderer,
    ) {
    }
}
