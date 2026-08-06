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
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;

/**
 * Marks a method as an MCP tool, registered through `ServerBuilder::register()`.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsTool
{
    /**
     * @param null|non-empty-string     $name
     * @param null|non-empty-string     $title
     * @param null|non-empty-string     $description
     * @param null|list<Icon>           $icons
     * @param null|array<string, mixed> $outputSchema
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        public ?string $name = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?ToolAnnotations $annotations = null,
        public ?array $icons = null,
        public ?array $outputSchema = null,
        public ?array $meta = null,
    ) {
    }
}
