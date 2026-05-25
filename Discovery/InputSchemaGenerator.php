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

namespace Nexus\Mcp\Server\Discovery;

use Nexus\Mcp\Server\Attribute\InputSchema;
use Nexus\Mcp\Server\Exception\SchemaGenerationException;
use Nexus\Mcp\Server\Exception\UnsupportedSchemaTypeException;
use Nexus\Mcp\Server\ServerContext;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * Builds a tool's `inputSchema` from a handler method's signature, docblock, and `#[InputSchema]` attributes.
 *
 * @internal
 */
final readonly class InputSchemaGenerator
{
    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function __construct(private DocBlockTypeResolver $resolver = new DocBlockTypeResolver(), private TypeNodeSchemaMapper $mapper = new TypeNodeSchemaMapper())
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SchemaGenerationException
     */
    public function generate(\ReflectionMethod $method): array
    {
        $methodAttribute = self::attribute($method);

        if (null !== $methodAttribute && null !== $methodAttribute->definition) {
            return self::ensureDialect($methodAttribute->definition);
        }

        $properties = [];
        $required = [];
        $paramTags = $this->resolver->paramTags($method);

        foreach ($method->getParameters() as $parameter) {
            if (self::isInjected($parameter)) {
                continue;
            }

            $name = $parameter->getName();
            $properties[$name] = $this->buildParameterSchema($parameter, $paramTags[$name] ?? null);

            if (! $parameter->isOptional()) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', '$schema' => self::DIALECT];

        if ([] !== $properties) {
            $schema['properties'] = $properties;
        }

        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParameterSchema(\ReflectionParameter $parameter, ?ParamTagValueNode $tag): array
    {
        $attribute = self::attribute($parameter);

        if (null !== $attribute && null !== $attribute->definition) {
            return $attribute->definition;
        }

        $explicit = $attribute?->toArray() ?? [];
        $inferred = isset($explicit['type']) ? [] : $this->inferType($parameter, $tag?->type);

        if (null !== $tag && '' !== $tag->description) {
            $inferred['description'] = $tag->description;
        }

        if ($parameter->isDefaultValueAvailable()) {
            $inferred['default'] = self::normaliseDefault($parameter->getDefaultValue());
        }

        return array_merge($inferred, $explicit);
    }

    /**
     * @return array<string, mixed>
     */
    private function inferType(\ReflectionParameter $parameter, ?TypeNode $docNode): array
    {
        $native = $parameter->getType();
        $nativeNode = $native instanceof \ReflectionType ? $this->resolver->parseNativeType((string) $native) : null;
        $core = $this->mapper->chooseCore($nativeNode, $docNode);

        if (null === $core) {
            return [];
        }

        try {
            $schema = $this->mapper->map($core);
        } catch (UnsupportedSchemaTypeException $exception) {
            $function = $parameter->getDeclaringFunction();
            $class = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() : '{closure}';

            throw new SchemaGenerationException($class, $function->getName(), $parameter->getName(), $exception->getMessage(), $exception);
        }

        return self::allowsNull($parameter) ? $this->mapper->nullable($schema) : $schema;
    }

    private static function allowsNull(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionType && $type->allowsNull();
    }

    private static function normaliseDefault(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => $value,
        };
    }

    private static function isInjected(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && ServerContext::class === $type->getName();
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function ensureDialect(array $schema): array
    {
        if (! \array_key_exists('$schema', $schema)) {
            $schema = ['$schema' => self::DIALECT] + $schema;
        }

        return $schema;
    }

    private static function attribute(\ReflectionMethod|\ReflectionParameter $reflection): ?InputSchema
    {
        $attributes = $reflection->getAttributes(InputSchema::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
