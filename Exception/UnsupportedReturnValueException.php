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
 * Thrown when a discovered handler returns a value the adapter cannot map to its result type.
 */
final class UnsupportedReturnValueException extends \LogicException implements McpExceptionInterface
{
    public function __construct(string $class, string $method, string $expected, mixed $value)
    {
        parent::__construct(\sprintf(
            '%s::%s() must return %s, %s given.',
            $class,
            $method,
            $expected,
            get_debug_type($value),
        ));
    }
}
