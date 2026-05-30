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

use Nexus\Mcp\Server\Exception\UnsupportedSchemaTypeException;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Maps a phpdoc-parser type node to a JSON Schema fragment, throwing on a type it cannot represent.
 *
 * @internal
 */
final class TypeNodeSchemaMapper
{
    private const array ARRAY_BASES = ['array', 'list', 'iterable', 'non-empty-array', 'non-empty-list'];

    /**
     * @return array<string, mixed>
     *
     * @throws UnsupportedSchemaTypeException
     */
    public function map(TypeNode $node): array
    {
        return match (true) {
            $node instanceof IdentifierTypeNode => self::mapIdentifier($node),
            $node instanceof NullableTypeNode => $this->makeNullable($this->map($node->type)),
            $node instanceof UnionTypeNode => $this->mapUnion($node),
            $node instanceof GenericTypeNode => $this->mapGeneric($node),
            $node instanceof ArrayTypeNode => self::buildArraySchema($this->map($node->type)),
            $node instanceof ArrayShapeNode => $this->mapArrayShape($node),
            $node instanceof ObjectShapeNode => $this->mapNamedShape($node),
            $node instanceof ConstTypeNode => self::mapConstUnion([$node], (string) $node),
            default => throw new UnsupportedSchemaTypeException((string) $node),
        };
    }

    /**
     * Resolves the type node to map. The docblock type is used when there is no native type, or when it
     * refines the native one. Otherwise the native type wins.
     */
    public function resolveTypeNode(?TypeNode $native, ?TypeNode $doc): ?TypeNode
    {
        $nativeCore = null !== $native ? self::stripNull($native) : null;
        $docCore = null !== $doc ? self::stripNull($doc) : null;

        if (null === $nativeCore) {
            return $docCore;
        }

        if (null !== $docCore && $this->docRefinesNative($nativeCore, $docCore)) {
            return $docCore;
        }

        return $nativeCore;
    }

    /**
     * Widens a schema's `type` (and, for an enum, its values) to admit `null`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function makeNullable(array $schema): array
    {
        if (! \array_key_exists('type', $schema)) {
            return $schema;
        }

        $type = $schema['type'];
        $types = \is_array($type)
            ? array_values(array_filter($type, is_string(...)))
            : (\is_string($type) ? [$type] : []);
        $types[] = 'null';
        $schema['type'] = self::normaliseTypeList($types);

        if (isset($schema['enum']) && \is_array($schema['enum']) && ! \in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    /**
     * A docblock type refines the native type when it is a mappable array shape or element type over a bare
     * native `array`/`iterable`, or maps to the same base type as the native one.
     */
    private function docRefinesNative(TypeNode $native, TypeNode $doc): bool
    {
        if (self::isBareArray($native) && self::isArrayLike($doc)) {
            return $this->isMappable($doc);
        }

        return $this->sharesBaseType($native, $doc);
    }

    private function isMappable(TypeNode $node): bool
    {
        try {
            $this->map($node);
        } catch (UnsupportedSchemaTypeException) {
            return false;
        }

        return true;
    }

    private function sharesBaseType(TypeNode $native, TypeNode $doc): bool
    {
        try {
            $nativeType = $this->map($native)['type'] ?? null;
            $docType = $this->map($doc)['type'] ?? null;
        } catch (UnsupportedSchemaTypeException) {
            return false;
        }

        return $nativeType === $docType;
    }

    private static function stripNull(TypeNode $node): TypeNode
    {
        if ($node instanceof NullableTypeNode) {
            return $node->type;
        }

        if ($node instanceof UnionTypeNode) {
            $rest = array_values(array_filter($node->types, static fn(TypeNode $member): bool => ! self::isNull($member)));

            return match (\count($rest)) {
                0 => $node,
                1 => $rest[0],
                default => new UnionTypeNode($rest),
            };
        }

        return $node;
    }

    private static function isBareArray(TypeNode $node): bool
    {
        return $node instanceof IdentifierTypeNode && \in_array($node->name, ['array', 'iterable'], true);
    }

    private static function isArrayLike(TypeNode $node): bool
    {
        return $node instanceof ArrayTypeNode || $node instanceof ArrayShapeNode || $node instanceof GenericTypeNode;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapIdentifier(IdentifierTypeNode $node): array
    {
        return match (strtolower($node->name)) {
            'int', 'integer' => ['type' => 'integer'],
            'positive-int' => ['type' => 'integer', 'minimum' => 1],
            'negative-int' => ['type' => 'integer', 'maximum' => -1],
            'non-negative-int' => ['type' => 'integer', 'minimum' => 0],
            'non-positive-int' => ['type' => 'integer', 'maximum' => 0],
            'float', 'double' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'non-empty-string' => ['type' => 'string', 'minLength' => 1],
            'array', 'iterable', 'list' => ['type' => 'array'],
            'object', 'stdclass' => ['type' => 'object'],
            'mixed' => [],
            'null' => ['type' => 'null'],
            default => self::mapClassName($node->name),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapClassName(string $name): array
    {
        if (enum_exists($name)) {
            return self::mapEnum($name);
        }

        throw new UnsupportedSchemaTypeException($name);
    }

    /**
     * @param class-string<\UnitEnum> $enum
     *
     * @return array{type: string, enum: list<int|string>}
     */
    private static function mapEnum(string $enum): array
    {
        $reflection = new \ReflectionEnum($enum);
        $values = array_map(
            static function (\ReflectionEnumUnitCase $case): int|string {
                $instance = $case->getValue();

                return $instance instanceof \BackedEnum ? $instance->value : $case->getName();
            },
            $reflection->getCases(),
        );

        $backingType = $reflection->getBackingType();
        $type = null !== $backingType && $backingType->getName() === 'int' ? 'integer' : 'string';

        return ['type' => $type, 'enum' => array_values($values)];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapUnion(UnionTypeNode $node): array
    {
        $nonNull = [];
        $hasNull = false;

        foreach ($node->types as $member) {
            if (self::isNull($member)) {
                $hasNull = true;

                continue;
            }

            $nonNull[] = $member;
        }

        if ([] === $nonNull) {
            throw new UnsupportedSchemaTypeException((string) $node);
        }

        if (\count($nonNull) === 1) {
            $schema = $this->map($nonNull[0]);
        } elseif ($nonNull[0] instanceof ConstTypeNode) {
            $schema = self::mapConstUnion($nonNull, (string) $node);
        } else {
            $types = [];

            foreach ($nonNull as $member) {
                $mapped = $this->map($member);
                $type = $mapped['type'] ?? null;

                if (\count($mapped) !== 1 || ! \is_string($type)) {
                    throw new UnsupportedSchemaTypeException((string) $node);
                }

                $types[] = $type;
            }

            $schema = ['type' => self::normaliseTypeList($types)];
        }

        return $hasNull ? $this->makeNullable($schema) : $schema;
    }

    /**
     * Collects a union of string or integer literals into a single typed enum, rejecting any other const
     * expression and any mix of literal types.
     *
     * @param non-empty-list<TypeNode> $members
     *
     * @return array{type: string, enum: non-empty-list<int|string>}
     */
    private static function mapConstUnion(array $members, string $label): array
    {
        [$type, $value] = self::buildConstMember($members[0], $label);
        $enum = [$value];

        foreach (\array_slice($members, 1) as $member) {
            [$memberType, $memberValue] = self::buildConstMember($member, $label);

            if ($memberType !== $type) {
                throw new UnsupportedSchemaTypeException($label);
            }

            $enum[] = $memberValue;
        }

        return ['type' => $type, 'enum' => $enum];
    }

    /**
     * @return array{string, int|string}
     */
    private static function buildConstMember(TypeNode $node, string $label): array
    {
        if (! $node instanceof ConstTypeNode) {
            throw new UnsupportedSchemaTypeException($label);
        }

        $expr = $node->constExpr;

        return match (true) {
            $expr instanceof ConstExprStringNode => ['string', $expr->value],
            $expr instanceof ConstExprIntegerNode => ['integer', (int) $expr->value],
            default => throw new UnsupportedSchemaTypeException($label),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGeneric(GenericTypeNode $node): array
    {
        if ('int' === $node->type->name) {
            return self::mapIntRange($node);
        }

        if (! \in_array($node->type->name, self::ARRAY_BASES, true)) {
            throw new UnsupportedSchemaTypeException((string) $node);
        }

        $arguments = $node->genericTypes;

        if (isset($arguments[0]) && ! isset($arguments[1])) {
            return self::buildArraySchema($this->map($arguments[0]));
        }

        if (isset($arguments[0], $arguments[1]) && ! isset($arguments[2])) {
            $keyNode = $arguments[0];
            $value = $this->map($arguments[1]);

            if ($keyNode instanceof IdentifierTypeNode && \in_array($keyNode->name, ['int', 'integer'], true)) {
                return self::buildArraySchema($value);
            }

            return [] === $value
                ? ['type' => 'object', 'additionalProperties' => true]
                : ['type' => 'object', 'additionalProperties' => $value];
        }

        throw new UnsupportedSchemaTypeException((string) $node);
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapIntRange(GenericTypeNode $node): array
    {
        $arguments = $node->genericTypes;

        if (! isset($arguments[0], $arguments[1]) || isset($arguments[2])) {
            throw new UnsupportedSchemaTypeException((string) $node);
        }

        $schema = ['type' => 'integer'];

        $minimum = self::readIntBound($arguments[0], 'min', (string) $node);

        if (null !== $minimum) {
            $schema['minimum'] = $minimum;
        }

        $maximum = self::readIntBound($arguments[1], 'max', (string) $node);

        if (null !== $maximum) {
            $schema['maximum'] = $maximum;
        }

        return $schema;
    }

    private static function readIntBound(TypeNode $node, string $sentinel, string $label): ?int
    {
        if ($node instanceof ConstTypeNode && $node->constExpr instanceof ConstExprIntegerNode) {
            return (int) $node->constExpr->value;
        }

        if ($node instanceof IdentifierTypeNode && $node->name === $sentinel) {
            return null;
        }

        throw new UnsupportedSchemaTypeException($label);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArrayShape(ArrayShapeNode $node): array
    {
        return self::isPositionalShape($node)
            ? $this->mapTuple($node)
            : $this->mapNamedShape($node);
    }

    private static function isPositionalShape(ArrayShapeNode $node): bool
    {
        foreach ($node->items as $item) {
            if (null !== $item->keyName) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTuple(ArrayShapeNode $node): array
    {
        $prefixItems = [];

        foreach ($node->items as $item) {
            $prefixItems[] = $this->map($item->valueType);
        }

        return [
            'type' => 'array',
            'prefixItems' => $prefixItems,
            'items' => false,
            'minItems' => \count($prefixItems),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapNamedShape(ArrayShapeNode|ObjectShapeNode $node): array
    {
        $properties = [];
        $required = [];

        foreach ($node->items as $item) {
            $key = self::resolveShapeKey($item);

            if (null === $key) {
                throw new UnsupportedSchemaTypeException((string) $node);
            }

            $properties[$key] = $this->map($item->valueType);

            if (! $item->optional) {
                $required[] = $key;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private static function resolveShapeKey(ArrayShapeItemNode|ObjectShapeItemNode $item): ?string
    {
        return $item->keyName instanceof IdentifierTypeNode ? $item->keyName->name : null;
    }

    /**
     * @param array<string, mixed> $items
     *
     * @return array<string, mixed>
     */
    private static function buildArraySchema(array $items): array
    {
        return [] === $items ? ['type' => 'array'] : ['type' => 'array', 'items' => $items];
    }

    private static function isNull(TypeNode $node): bool
    {
        return $node instanceof IdentifierTypeNode && 'null' === $node->name;
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>|string
     */
    private static function normaliseTypeList(array $types): array|string
    {
        $types = array_values(array_unique($types));

        if (\count($types) === 1) {
            return $types[0];
        }

        $hasNull = \in_array('null', $types, true);
        $rest = array_filter($types, static fn(string $type): bool => 'null' !== $type);
        sort($rest);

        if ($hasNull) {
            array_unshift($rest, 'null');
        }

        return $rest;
    }
}
