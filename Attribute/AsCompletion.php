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

/**
 * Marks a method as a completion provider for one prompt argument or resource template
 * variable, registered through `ServerBuilder::register()`.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class AsCompletion
{
    /**
     * @param string      $argument    Name of the prompt argument or template variable being completed
     * @param null|string $prompt      Name of the prompt the argument belongs to
     * @param null|string $uriTemplate URI template the variable belongs to
     */
    public function __construct(
        public string $argument,
        public ?string $prompt = null,
        public ?string $uriTemplate = null,
    ) {
    }
}
