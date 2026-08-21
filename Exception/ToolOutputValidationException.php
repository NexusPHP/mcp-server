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
use Nexus\Mcp\Server\Validation\SchemaViolation;

/**
 * Thrown when a tool's result violates its declared `outputSchema`, by non-conformance or by carrying
 * no `structuredContent` at all.
 */
final class ToolOutputValidationException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * @param list<SchemaViolation> $errors Conformance failures, empty when the result carried no `structuredContent`
     */
    public function __construct(string $toolName, array $errors, ?\Throwable $previous = null)
    {
        parent::__construct(
            [] === $errors
                ? \sprintf('Tool "%s" declares an outputSchema but its result carries no structuredContent.', $toolName)
                : \sprintf(
                    'Tool "%s" returned structuredContent that does not conform to its outputSchema: %s',
                    $toolName,
                    implode(' ', array_map(static fn(SchemaViolation $violation): string => $violation->message, $errors)),
                ),
            previous: $previous,
        );
    }
}
