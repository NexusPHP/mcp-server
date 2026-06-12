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
 * Thrown when an expanded DTO declares a constructor parameter that is itself a
 * class the binder cannot construct from a value map.
 */
final class UnsupportedNestedParameterException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $class
     */
    public function __construct(string $class, string $parameter, string $type)
    {
        parent::__construct(\sprintf(
            '%s declares constructor parameter "$%s" of type "%s", which the binder cannot construct from a value map. Nested object expansion is not supported.',
            $class,
            $parameter,
            $type,
        ));
    }
}
