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
 * Thrown when a discovered prompt, resource, or resource-template method declares a
 * parameter whose type cannot be satisfied by a string.
 */
final class UnsupportedParameterTypeException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $class
     */
    public function __construct(string $class, string $method, string $parameter, string $type)
    {
        parent::__construct(\sprintf(
            '%s::%s() declares parameter "$%s" of unsupported type "%s". It is bound from a string value.',
            $class,
            $method,
            $parameter,
            $type,
        ));
    }
}
