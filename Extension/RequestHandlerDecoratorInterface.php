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

namespace Nexus\Mcp\Server\Extension;

use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Server\ServerContext;

/**
 * Optional add-on to `ServerExtensionInterface` for extensions decorating the
 * handlers that serve spec-registry request methods.
 *
 * @phpstan-type RequestHandlerDecorator \Closure(RequestHandlerInterface<non-empty-string, Result, ServerContext>): RequestHandlerInterface<non-empty-string, Result, ServerContext>
 */
interface RequestHandlerDecoratorInterface
{
    /**
     * Decorator closures keyed by the spec-registry request method they wrap.
     *
     * @return array<non-empty-string, RequestHandlerDecorator>
     */
    public function getRequestHandlerDecorators(): array;
}
