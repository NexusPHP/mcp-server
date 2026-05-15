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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Read surface that the built-in `prompts/*` request handlers depend on.
 */
interface PromptStoreInterface
{
    /**
     * @throws InvalidCursorException
     */
    public function list(?Cursor $cursor): ListPromptsResult;

    /**
     * @param null|array<string, string> $arguments
     *
     * @throws PromptNotFoundException
     */
    public function get(string $name, ?array $arguments, ServerContext $context): GetPromptResult;
}
