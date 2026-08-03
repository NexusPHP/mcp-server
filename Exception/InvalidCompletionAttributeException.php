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
 * Thrown when a method declares an `#[AsCompletion]` attribute whose arguments are invalid.
 */
final class InvalidCompletionAttributeException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $class
     */
    public function __construct(string $class, string $method, string $reason)
    {
        parent::__construct(\sprintf(
            '%s::%s() declares an invalid #[AsCompletion] attribute: %s.',
            $class,
            $method,
            $reason,
        ));
    }
}
