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

namespace Nexus\Mcp\Server\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when a registered source declares an entry under a key an earlier source already declared.
 */
final class DuplicateDiscoveredEntryException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $kind
     * @param non-empty-string $key
     * @param class-string     $source
     * @param class-string     $owner
     */
    public function __construct(string $kind, string $key, string $source, string $owner)
    {
        parent::__construct(\sprintf('"%s" declares %s "%s", which "%s" already declares.', $source, $kind, $key, $owner));
    }
}
