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
use Nexus\Mcp\Server\ListChangeSourceInterface;

/**
 * A prompt store whose listing can be changed after `build()`.
 */
interface MutablePromptStoreInterface extends ListChangeSourceInterface, PromptStoreInterface
{
    /**
     * Registers a prompt, replacing any prompt already listed under the same name.
     */
    public function addPrompt(Prompt $prompt, PromptRendererInterface $renderer): void;

    /**
     * Removes the prompt listed under `$name`, reporting whether one was listed.
     */
    public function removePrompt(string $name): bool;
}
