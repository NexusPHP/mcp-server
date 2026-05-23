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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Read surface that the built-in `tools/*` request handlers depend on.
 */
interface ToolStoreInterface
{
    /**
     * @throws InvalidCursorException
     */
    public function list(?Cursor $cursor): ListToolsResult;

    /**
     * @param null|array<string, mixed> $arguments
     *
     * @throws InvalidParamsException
     * @throws ToolNotFoundException
     * @throws ToolOutputValidationException
     */
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult;
}
