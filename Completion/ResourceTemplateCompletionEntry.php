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

namespace Nexus\Mcp\Server\Completion;

/**
 * A discovered completion provider paired with the resource template variable it completes.
 */
final readonly class ResourceTemplateCompletionEntry
{
    /**
     * @param non-empty-string $uriTemplate
     * @param non-empty-string $argument
     */
    public function __construct(
        public string $uriTemplate,
        public string $argument,
        public CompletionProviderInterface $provider,
    ) {
    }
}
