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

use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Server\ListChangeSourceInterface;

/**
 * A resource template store whose listing can be changed after `build()`.
 */
interface MutableResourceTemplateStoreInterface extends ListChangeSourceInterface, ResourceTemplateStoreInterface
{
    /**
     * Registers a template, replacing any template already listed under the same URI template.
     */
    public function addResourceTemplate(ResourceTemplate $template, TemplatedResourceReaderInterface $reader): void;

    /**
     * Removes the template listed under `$uriTemplate`, reporting whether one was listed.
     */
    public function removeResourceTemplate(string $uriTemplate): bool;
}
