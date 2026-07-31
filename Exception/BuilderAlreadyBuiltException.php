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

namespace Nexus\Mcp\Server\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when `ServerBuilder::build()` is called a second time on the same builder.
 */
final class BuilderAlreadyBuiltException extends \LogicException implements McpExceptionInterface
{
    public function __construct()
    {
        parent::__construct('This builder has already been built. Construct a new ServerBuilder for another server.');
    }
}
