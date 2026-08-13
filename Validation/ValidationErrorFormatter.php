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

use Opis\JsonSchema\Errors\ValidationError;

/**
 * Formatter rendering opis validation errors per the diagnostic message conventions.
 *
 * @internal
 */
final class ValidationErrorFormatter
{
    /**
     * @return list<string>
     */
    public function format(ValidationError $error): array
    {
        $messages = [];

        foreach (self::collectLeafErrors($error) as $leaf) {
            foreach (self::formatLeafError($leaf) as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return iterable<int, ValidationError>
     */
    private static function collectLeafErrors(ValidationError $error): iterable
    {
        $subErrors = $error->subErrors();

        if ([] === $subErrors) {
            yield $error;
        } else {
            foreach ($subErrors as $subError) {
                \assert($subError instanceof ValidationError);

                yield from self::collectLeafErrors($subError);
            }
        }
    }

    /**
     * Renders a leaf error, bare at the data root since the caller's wrapper supplies the scope.
     *
     * @return list<string>
     */
    private static function formatLeafError(ValidationError $error): array
    {
        $path = $error->data()->fullPath();
        $label = [] === $path ? null : implode('.', $path);
        $args = $error->args();

        switch ($error->keyword()) {
            case 'type':
                $expected = $args['expected'] ?? null;
                $actual = $args['type'] ?? null;
                \assert(\is_string($actual));

                return [\sprintf(
                    '%smust be %s, %s given.',
                    null === $label ? '' : \sprintf('"%s" ', $label),
                    implode(' or ', array_map(self::describeJsonType(...), \is_array($expected) ? $expected : [$expected])),
                    self::describePhpType($actual),
                )];

            case 'required':
                $missing = $args['missing'] ?? null;
                \assert(\is_array($missing));

                $messages = [];

                foreach ($missing as $key) {
                    \assert(\is_string($key));
                    $messages[] = null === $label
                        ? \sprintf('missing the required "%s" key.', $key)
                        : \sprintf('"%s" is missing the required "%s" key.', $label, $key);
                }

                return $messages;

            case 'additionalProperties':
                $properties = $args['properties'] ?? null;
                \assert(\is_array($properties));

                $messages = [];

                foreach ($properties as $property) {
                    \assert(\is_string($property));
                    $messages[] = null === $label
                        ? \sprintf('carries the undeclared "%s" key.', $property)
                        : \sprintf('"%s" carries the undeclared "%s" key.', $label, $property);
                }

                return $messages;

            case 'enum':
                $schemaData = $error->schema()->info()->data();
                \assert($schemaData instanceof \stdClass);
                $allowed = $schemaData->enum;
                \assert(\is_array($allowed));

                return [\sprintf(
                    '%smust be one of [%s], %s given.',
                    null === $label ? '' : \sprintf('"%s" ', $label),
                    implode(', ', array_map(static fn(mixed $value): string => var_export($value, true), $allowed)),
                    var_export($error->data()->value(), true),
                )];

            default:
                return [\sprintf(
                    '%sdoes not satisfy the schema\'s "%s" constraint.',
                    null === $label ? 'the value ' : \sprintf('"%s" ', $label),
                    $error->keyword(),
                )];
        }
    }

    private static function describeJsonType(mixed $type): string
    {
        \assert(\is_string($type));

        return match ($type) {
            'array' => 'an array',
            'integer' => 'an integer',
            'object' => 'an object',
            'boolean' => 'a boolean',
            'number' => 'a number',
            'string' => 'a string',
            default => $type,
        };
    }

    /**
     * Maps a JSON-space type name to the `{type} given.` idiom, where a JSON object
     * decodes to a PHP array on this SDK's transports.
     */
    private static function describePhpType(string $type): string
    {
        return match ($type) {
            'boolean' => 'bool',
            'integer' => 'int',
            'number' => 'float',
            'object' => 'array',
            default => $type,
        };
    }
}
