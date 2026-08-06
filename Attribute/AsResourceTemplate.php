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

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Icon;

/**
 * Marks a method as an MCP resource template, registered through `ServerBuilder::register()`.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsResourceTemplate
{
    /**
     * @param null|non-empty-string     $name
     * @param null|non-empty-string     $title
     * @param null|list<Icon>           $icons
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        public string $uriTemplate,
        public ?string $name = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?Annotations $annotations = null,
        public ?array $icons = null,
        public ?array $meta = null,
    ) {
    }
}
