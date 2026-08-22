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

use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Default `SchemaValidatorInterface` backed by opis/json-schema.
 */
final readonly class OpisSchemaValidator implements SchemaValidatorInterface
{
    private const array SCHEMA_KEYWORDS = [
        'additionalProperties',
        'contains',
        'else',
        'if',
        'items',
        'not',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];
    private const array SCHEMA_MAP_KEYWORDS = ['$defs', 'dependentSchemas', 'patternProperties', 'properties'];
    private const array SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];
    private const int MAX_ERRORS = 8;

    private Validator $validator;
    private ValidationErrorFormatter $formatter;

    public function __construct()
    {
        // `SafeDisplay` caps the composed diagnostic at 256 characters, so a deeper walk is never peer-visible.
        $this->validator = new Validator(max_errors: self::MAX_ERRORS);
        $this->formatter = new ValidationErrorFormatter();
    }

    #[\Override]
    public function validate(mixed $data, array $schema): array
    {
        $error = $this->validator->validate(
            Helper::toJSON($data),
            (object) Helper::toJSON($this->normaliseSubSchemas($schema)),
        )->error();

        return null === $error ? [] : $this->formatter->format($error);
    }

    /**
     * Restores the always-valid `{}` that `json_decode(..., true)` renders as PHP `[]` in every sub-schema position.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private function normaliseSubSchemas(array $schema): array
    {
        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (isset($schema[$keyword]) && \is_array($schema[$keyword])) {
                $schema[$keyword] = $this->asSchemaObject($schema[$keyword]);
            }
        }

        foreach (self::SCHEMA_MAP_KEYWORDS as $keyword) {
            if (! isset($schema[$keyword]) || ! \is_array($schema[$keyword])) {
                continue;
            }

            $map = $schema[$keyword];

            if ([] === $map) {
                $schema[$keyword] = new \stdClass();

                continue;
            }

            foreach ($map as $name => $subSchema) {
                if (\is_array($subSchema)) {
                    $map[$name] = $this->asSchemaObject($subSchema);
                }
            }

            $schema[$keyword] = $map;
        }

        foreach (self::SCHEMA_LIST_KEYWORDS as $keyword) {
            if (! isset($schema[$keyword]) || ! \is_array($schema[$keyword])) {
                continue;
            }

            $list = $schema[$keyword];

            foreach ($list as $index => $subSchema) {
                if (\is_array($subSchema)) {
                    $list[$index] = $this->asSchemaObject($subSchema);
                }
            }

            $schema[$keyword] = $list;
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $subSchema
     *
     * @return array<array-key, mixed>|\stdClass
     */
    private function asSchemaObject(array $subSchema): array|\stdClass
    {
        return [] === $subSchema ? new \stdClass() : $this->normaliseSubSchemas($subSchema);
    }
}
