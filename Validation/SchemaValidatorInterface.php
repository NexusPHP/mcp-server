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

namespace Nexus\Mcp\Server\Validation;

/**
 * Validates a decoded value against a JSON Schema.
 */
interface SchemaValidatorInterface
{
    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<SchemaViolation>
     */
    public function validate(mixed $data, array $schema): array;
}
