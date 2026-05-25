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

namespace Nexus\Mcp\Server\Attribute;

/**
 * JSON Schema constraints for a discovered tool's input, applied at the parameter or method level.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final readonly class InputSchema
{
    /**
     * @param null|array<string, mixed>      $definition           Full schema override
     * @param null|list<mixed>               $enum
     * @param null|array<string, mixed>      $items
     * @param null|array<string, mixed>      $properties
     * @param null|list<string>              $required
     * @param null|array<string, mixed>|bool $additionalProperties
     */
    public function __construct(
        public ?array $definition = null,
        public ?string $type = null,
        public ?string $description = null,
        public ?array $enum = null,
        public ?string $format = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public null|float|int $minimum = null,
        public null|float|int $maximum = null,
        public null|float|int $exclusiveMinimum = null,
        public null|float|int $exclusiveMaximum = null,
        public null|float|int $multipleOf = null,
        public ?array $items = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?array $properties = null,
        public ?array $required = null,
        public null|array|bool $additionalProperties = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (null !== $this->definition) {
            return $this->definition;
        }

        return array_filter([
            'type' => $this->type,
            'description' => $this->description,
            'enum' => $this->enum,
            'format' => $this->format,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'exclusiveMinimum' => $this->exclusiveMinimum,
            'exclusiveMaximum' => $this->exclusiveMaximum,
            'multipleOf' => $this->multipleOf,
            'items' => $this->items,
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'uniqueItems' => $this->uniqueItems,
            'properties' => $this->properties,
            'required' => $this->required,
            'additionalProperties' => $this->additionalProperties,
        ], static fn(mixed $value): bool => null !== $value);
    }
}
