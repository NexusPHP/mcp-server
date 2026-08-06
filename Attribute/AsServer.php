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

namespace Nexus\Mcp\Server\Attribute;

use Nexus\Mcp\Core\Schema\Icon;

/**
 * Supplies the MCP server's identity and instructions, applied through `ServerBuilder::register()`.
 * At most one registered source may carry this attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsServer
{
    /**
     * @param non-empty-string      $name
     * @param non-empty-string      $version
     * @param null|non-empty-string $title
     * @param null|non-empty-string $description
     * @param null|non-empty-string $websiteUrl
     * @param null|list<Icon>       $icons
     */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $websiteUrl = null,
        public ?string $instructions = null,
        public ?array $icons = null,
    ) {
    }
}
