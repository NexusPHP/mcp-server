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

namespace Nexus\Mcp\Server;

/**
 * A store that can answer whether an entry is registered without listing or serving it.
 */
interface RegistrationLookupInterface
{
    /**
     * Whether an entry is registered under `$key`: a prompt name, a resource URI or a URI template, or a concrete
     * URI that a registered template serves.
     */
    public function isRegistered(string $key): bool;
}
