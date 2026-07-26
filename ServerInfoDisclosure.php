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

use Nexus\Mcp\Core\Schema\Implementation;

/**
 * How much of the server's identity rides the `_meta` of the results it sends,
 * outside the `server/discover` result that always carries the whole block.
 */
enum ServerInfoDisclosure
{
    /**
     * The whole `Implementation`, icons and descriptive fields included.
     */
    case Full;

    /**
     * Only the two fields the spec requires, so a client that already discovered the
     * server does not receive its icons and descriptions again on every response.
     */
    case NameAndVersion;

    /**
     * No identity at all, including on `server/discover`.
     */
    case None;

    /**
     * Narrows an identity to what this level discloses, or null when it discloses none.
     *
     * @internal
     */
    public function project(Implementation $serverInfo): ?Implementation
    {
        return match ($this) {
            self::Full => $serverInfo,
            self::NameAndVersion => new Implementation(name: $serverInfo->name, version: $serverInfo->version),
            self::None => null,
        };
    }
}
