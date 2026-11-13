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

use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Tool\Tool;

/**
 * Optional add-on to `ServerExtensionInterface` for extensions that hold the builder's registrations to their rules.
 */
interface RegistrationValidatorInterface
{
    /**
     * Runs once in `build()` over the tools, resources and templates registered on the builder, not a custom store's.
     *
     * @param list<Tool>             $tools
     * @param list<Resource>         $resources
     * @param list<ResourceTemplate> $templates
     *
     * @throws LogicException
     */
    public function validateRegistrations(array $tools, array $resources, array $templates): void;
}
