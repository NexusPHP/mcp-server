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

use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts a closure to the `TemplatedResourceReaderInterface` contract.
 */
final readonly class ClosureTemplatedResourceReader implements TemplatedResourceReaderInterface
{
    /**
     * @param \Closure(string, array<string, string>, ServerContext): ReadResourceResult $closure
     */
    public function __construct(private \Closure $closure)
    {
    }

    #[\Override]
    public function read(string $uri, array $bindings, ServerContext $context): ReadResourceResult
    {
        return ($this->closure)($uri, $bindings, $context);
    }
}
