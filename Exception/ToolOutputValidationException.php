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
 * Thrown when a tool's `structuredContent` does not conform to its declared `outputSchema`.
 */
final class ToolOutputValidationException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $toolName, array $errors, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf(
                'Tool "%s" returned structuredContent that does not conform to its outputSchema: %s',
                $toolName,
                implode('; ', $errors),
            ),
            previous: $previous,
        );
    }
}
