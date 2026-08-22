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

use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Server\Attribute\InputSchema;
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

    public function __construct(
        private DocBlockTypeResolver $resolver = new DocBlockTypeResolver(),
        private TypeNodeSchemaMapper $mapper = new TypeNodeSchemaMapper(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException
     */
    public function generate(\ReflectionMethod $method): array
    {
        $methodAttribute = $this->readAttribute($method);

        if (null !== $methodAttribute && null !== $methodAttribute->definition) {
            return $this->ensureDialect($methodAttribute->definition);
        }

        [$properties, $required] = $this->buildObjectMembers(
            $method->getParameters(),
            $this->resolver->parseParamTags($method),
            true,
        );

        $schema = ['type' => 'object', '$schema' => self::DIALECT];

        if ([] !== $properties) {
            $schema['properties'] = $properties;
        }

        if ([] !== $required) {
            $schema['required'] = $required;
        }

        $explicit = $methodAttribute?->toArray() ?? [];

        // An inferred `required` names inferred properties, so replacing those discards it too.
        if (isset($explicit['properties'])) {
            unset($schema['required']);
        }

        return array_merge($schema, $explicit);
    }

    /**
     * Whether a class is expanded into an object input schema and constructed from its value map.
     *
     * @phpstan-assert-if-true class-string $class
     */
    public static function isExpandable(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() && ! $reflection->isInternal();
    }

    /**
     * Whether a parameter is the dependency-injected `ServerContext`, excluded from the argument surface.
     */
    public static function isInjectedContext(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === ServerContext::class;
    }

    /**
     * @return null|class-string
     */
    public static function resolveExpandableNativeClass(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return self::isExpandable($name) ? $name : null;
    }

    /**
     * @param iterable<\ReflectionParameter>   $parameters
     * @param array<string, ParamTagValueNode> $tags
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function buildObjectMembers(iterable $parameters, array $tags, bool $topLevel): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            if ($topLevel && self::isInjectedContext($parameter)) {
                continue;
            }

            $name = $parameter->getName();
            $properties[$name] = TypeNodeSchemaMapper::asSubSchema(
                $this->buildParameterSchema($parameter, $tags[$name] ?? null, $topLevel),
            );

            if (! $parameter->isOptional()) {
                $required[] = $name;
            }
        }

        return [$properties, $required];
    }

    /**
     * Builds an object schema from a class's constructor parameters without expanding members.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function expandClass(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            return ['type' => 'object'];
        }

        [$properties, $required] = $this->buildObjectMembers(
            $constructor->getParameters(),
            $this->resolver->parseParamTags($constructor),
            false,
        );

        $schema = ['type' => 'object'];

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
    private function buildParameterSchema(\ReflectionParameter $parameter, ?ParamTagValueNode $tag, bool $topLevel): array
    {
        $attribute = $this->readAttribute($parameter);

        if (null !== $attribute && null !== $attribute->definition) {
            return $attribute->definition;
        }

        $explicit = $attribute?->toArray() ?? [];

        if (isset($explicit['type'])) {
            $inferred = [];
        } else {
            $class = $topLevel ? self::resolveExpandableNativeClass($parameter) : null;
            $inferred = null !== $class
                ? $this->expandNativeClass($parameter, $class)
                : $this->inferType($parameter, $tag?->type);
        }

        if ($parameter->isVariadic()) {
            $inferred = TypeNodeSchemaMapper::buildArraySchema($inferred);
        }

        if (null !== $tag && '' !== $tag->description) {
            $inferred['description'] = $tag->description;
        }

        if ($parameter->isDefaultValueAvailable()) {
            $inferred['default'] = $this->normaliseDefault($parameter->getDefaultValue());
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
        $type = $this->mapper->resolveTypeNode($nativeNode, $docNode);

        if (null === $type) {
            return [];
        }

        try {
            $schema = $this->mapper->map($type);
        } catch (UnsupportedSchemaTypeException $exception) {
            $function = $parameter->getDeclaringFunction();
            $class = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() : '{closure}';

            throw new LogicException(\sprintf(
                'Cannot generate the input schema for parameter "$%s" of %s::%s(). %s Add #[InputSchema(...)] to describe it explicitly.',
                $parameter->getName(),
                $class,
                $function->getName(),
                $exception->getMessage(),
            ), previous: $exception);
        }

        return $this->allowsNull($parameter) ? $this->mapper->makeNullable($schema) : $schema;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function expandNativeClass(\ReflectionParameter $parameter, string $class): array
    {
        $schema = $this->expandClass($class);

        return $this->allowsNull($parameter) ? $this->mapper->makeNullable($schema) : $schema;
    }

    private function allowsNull(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionType && $type->allowsNull();
    }

    private function normaliseDefault(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function ensureDialect(array $schema): array
    {
        if (! \array_key_exists('$schema', $schema)) {
            $schema = ['$schema' => self::DIALECT] + $schema;
        }

        return $schema;
    }

    private function readAttribute(\ReflectionMethod|\ReflectionParameter $reflection): ?InputSchema
    {
        $attributes = $reflection->getAttributes(InputSchema::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
