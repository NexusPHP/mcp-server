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

namespace Nexus\Mcp\Server\Resource;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;

/**
 * Read surface that the built-in `resources/templates/*` request handlers depend on.
 */
interface ResourceTemplateStoreInterface
{
    /**
     * @throws InvalidCursorException
     */
    public function listTemplates(?Cursor $cursor): ListResourceTemplatesResult;
}
