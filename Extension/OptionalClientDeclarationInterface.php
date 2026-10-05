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

namespace Nexus\Mcp\Server\Extension;

/**
 * Optional add-on to `ServerExtensionInterface` for an extension whose requests a client may send without
 * declaring the extension among its own capabilities.
 */
interface OptionalClientDeclarationInterface
{
}
