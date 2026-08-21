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

use Nexus\Assert\Assert;

/**
 * One JSON Schema violation, located by an RFC 6901 pointer into the validated data.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6901
 */
final readonly class SchemaViolation
{
    /**
     * @param string           $pointer JSON pointer to the offending value
     * @param non-empty-string $message
     */
    public function __construct(
        public string $pointer,
        public string $message,
    ) {
        Assert::that($pointer)->matchesRegularExpression(
            '#\A(?:/(?:[^/~]|~[01])*)*\z#',
            'Schema violation pointer must be an RFC 6901 JSON pointer, {value} given.',
        );
        Assert::that($message)->isNonEmptyString('Schema violation message must be a non-empty string, {type} given.');
    }

    /**
     * @return array{pointer: string, message: non-empty-string}
     */
    public function toArray(): array
    {
        return ['pointer' => $this->pointer, 'message' => $this->message];
    }
}
