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
 * Thrown when a discovered resource template names a variable `uri`, which the reader method's `$uri`
 * parameter reserves for the request URI.
 */
final class ReservedTemplateVariableException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $class
     */
    public function __construct(string $class, string $method)
    {
        parent::__construct(\sprintf(
            '%s::%s() declares template variable "{uri}", which is reserved for the injected request URI. Rename the variable.',
            $class,
            $method,
        ));
    }
}
