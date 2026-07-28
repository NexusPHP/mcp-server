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
use Nexus\Mcp\Server\Discovery\ArgumentBinder;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts an attribute-discovered handler method to the `TemplatedResourceReaderInterface` contract.
 */
final readonly class ReflectedTemplatedResourceReader implements TemplatedResourceReaderInterface
{
    public function __construct(
        private object $handler,
        private \ReflectionMethod $method,
        private ArgumentBinder $binder = new ArgumentBinder(),
    ) {
    }

    #[\Override]
    public function read(string $uri, array $bindings, ServerContext $context): InputRequiredResult|ReadResourceResult
    {
        $bound = $this->binder->bind($this->method, ['uri' => $uri, ...$bindings], $context);

        return ReflectedResourceResult::adapt($this->method->invokeArgs($this->handler, $bound), $uri, $this->method);
    }
}
