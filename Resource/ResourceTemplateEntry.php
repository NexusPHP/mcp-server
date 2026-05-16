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

/**
 * Pairs a `ResourceTemplate` with the reader that serves URIs matching it.
 *
 * @internal
 */
final readonly class ResourceTemplateEntry
{
    public function __construct(public ResourceTemplate $template, public TemplatedResourceReaderInterface $reader)
    {
    }
}
