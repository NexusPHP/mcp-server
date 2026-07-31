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

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Server\ListChangeSourceInterface;

/**
 * A resource store whose listing can be changed after `build()`.
 */
interface MutableResourceStoreInterface extends ListChangeSourceInterface, ResourceStoreInterface
{
    /**
     * Registers a resource, replacing any resource already listed under the same URI.
     */
    public function addResource(Resource $resource, ResourceReaderInterface $reader): void;

    /**
     * Removes the resource listed under `$uri`, reporting whether one was listed.
     */
    public function removeResource(string $uri): bool;
}
