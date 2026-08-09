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

use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Reads a single resource whose URI matched a registered `ResourceTemplate`,
 * receiving the variable bindings the matcher extracted.
 */
interface TemplatedResourceReaderInterface
{
    /**
     * @param non-empty-string      $uri
     * @param array<string, string> $bindings
     */
    public function read(string $uri, array $bindings, ServerContext $context): InputRequiredResult|ReadResourceResult;
}
