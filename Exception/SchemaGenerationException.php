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
 * Thrown when a handler parameter's input schema cannot be generated.
 */
final class SchemaGenerationException extends \LogicException implements McpExceptionInterface
{
    public function __construct(
        string $class,
        string $method,
        string $parameter,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Cannot generate the input schema for parameter "$%s" of %s::%s(). %s Add #[InputSchema(...)] to describe it explicitly.',
                $parameter,
                $class,
                $method,
                $reason,
            ),
            previous: $previous,
        );
    }
}
