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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Server\ServerContext;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Binds a named value map to a handler method's parameters.
 *
 * @internal
 */
final class ArgumentBinder
{
    public function __construct(
        private readonly DocBlockTypeResolver $resolver = new DocBlockTypeResolver(),
        private readonly TypeNodeSchemaMapper $mapper = new TypeNodeSchemaMapper(),
    ) {
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     *
     * @throws InvalidParamsException
     * @throws LogicException
     */
    public function bind(\ReflectionMethod $method, array $values, ServerContext $context): array
    {
        try {
            return $this->resolveBindings($method, $values, $context);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidParamsException($context->requestId, SafeDisplay::sanitiseCause($e->getMessage()), $e);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     *
     * @throws \InvalidArgumentException
     * @throws LogicException
     */
    private function resolveBindings(\ReflectionMethod $method, array $values, ServerContext $context): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (InputSchemaGenerator::isInjectedContext($parameter)) {
                $arguments[] = $context;
            } elseif ($parameter->isVariadic()) {
                $list = $values[$name] ?? [];
                Assert::that($list)->isList(\sprintf('"%s" must be a list, {type} given.', $name));

                foreach ($list as $element) {
                    $arguments[] = $this->bindArgument($parameter, $element);
                }
            } elseif (\array_key_exists($name, $values)) {
                $arguments[] = $this->bindArgument($parameter, $values[$name]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(\sprintf('missing the required "%s" key.', $name));
            }
        }

        return $arguments;
    }

    private function bindArgument(\ReflectionParameter $parameter, mixed $value): mixed
    {
        if ($this->acceptsNull($parameter, $value)) {
            return null;
        }

        $class = InputSchemaGenerator::resolveExpandableNativeClass($parameter);

        return null !== $class
            ? $this->instantiate($class, $parameter->getName(), $value)
            : $this->hydrate($parameter, $value);
    }

    /**
     * Whether `$value` is the `null` permitted by a nullable parameter's advertised schema.
     */
    private function acceptsNull(\ReflectionParameter $parameter, mixed $value): bool
    {
        return null === $value && $parameter->getType()?->allowsNull() === true;
    }

    /**
     * @param class-string $class
     *
     * @throws \InvalidArgumentException
     * @throws LogicException
     */
    private function instantiate(string $class, string $argument, mixed $value): object
    {
        Assert::that($value)->isMap(\sprintf('"%s" must be an object, {type} given.', $argument));

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (\array_key_exists($name, $value)) {
                $this->guardAgainstNestedObject($class, $parameter);
                $arguments[] = $this->hydrate($parameter, $value[$name], $argument);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(\sprintf('"%s" is missing the required "%s" key.', $argument, $name));
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param class-string $class
     *
     * @throws LogicException
     */
    private function guardAgainstNestedObject(string $class, \ReflectionParameter $parameter): void
    {
        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $name = $type->getName();

        if (enum_exists($name)) {
            return;
        }

        throw new LogicException(\sprintf(
            '%s declares constructor parameter "$%s" of type "%s", which the binder cannot construct from a value map. Nested object expansion is not supported.',
            $class,
            $parameter->getName(),
            $name,
        ));
    }

    private function hydrate(\ReflectionParameter $parameter, mixed $value, ?string $scope = null): mixed
    {
        if ($this->acceptsNull($parameter, $value)) {
            return null;
        }

        $type = $parameter->getType();
        $label = null === $scope
            ? $parameter->getName()
            : \sprintf('%s.%s', $scope, $parameter->getName());

        if (! $type instanceof \ReflectionNamedType) {
            return $this->hydrateDocumented($parameter, $value, $label);
        }

        $name = $type->getName();

        if (\in_array(strtolower($name), ['object', 'stdclass'], true)) {
            if (\is_object($value)) {
                return $value;
            }

            Assert::that($value)->isMap(\sprintf('"%s" must be an object, {type} given.', $label));

            return (object) $value;
        }

        if (! $type->isBuiltin() && enum_exists($name)) {
            return $this->hydrateEnum($name, $value, $label);
        }

        return \in_array($name, ['array', 'iterable'], true)
            ? $this->hydrateDocumented($parameter, $value, $label)
            : $value;
    }

    /**
     * Converts the enum cases that a parameter's docblock type nests inside a collection or shape.
     */
    private function hydrateDocumented(\ReflectionParameter $parameter, mixed $value, string $label): mixed
    {
        $type = $parameter->getType();
        $native = $type instanceof \ReflectionType ? $this->resolver->parseNativeType((string) $type) : null;
        $tag = $this->resolver->parseParamTags($parameter->getDeclaringFunction())[$parameter->getName()] ?? null;
        $node = $this->mapper->resolveTypeNode($native, $tag?->type);

        return null === $node ? $value : $this->hydrateNode($node, $value, $label);
    }

    private function hydrateNode(TypeNode $node, mixed $value, string $label): mixed
    {
        return match (true) {
            $node instanceof IdentifierTypeNode => enum_exists($node->name)
                ? $this->hydrateEnum($node->name, $value, $label)
                : $value,
            $node instanceof NullableTypeNode => null === $value
                ? null
                : $this->hydrateNode($node->type, $value, $label),
            $node instanceof UnionTypeNode => $this->hydrateUnion($node, $value, $label),
            $node instanceof ArrayTypeNode => $this->hydrateElements($node->type, $value, $label),
            $node instanceof GenericTypeNode => $this->hydrateGeneric($node, $value, $label),
            $node instanceof ArrayShapeNode => $this->hydrateShape($node, $value, $label),
            default => $value,
        };
    }

    private function hydrateUnion(UnionTypeNode $node, mixed $value, string $label): mixed
    {
        $members = [];
        $nullable = false;

        foreach ($node->types as $member) {
            if ($member instanceof IdentifierTypeNode && 'null' === $member->name) {
                $nullable = true;
            } else {
                $members[] = $member;
            }
        }

        if (null === $value && $nullable) {
            return null;
        }

        return \count($members) === 1 ? $this->hydrateNode($members[0], $value, $label) : $value;
    }

    private function hydrateGeneric(GenericTypeNode $node, mixed $value, string $label): mixed
    {
        if (! \in_array((string) $node->type, TypeNodeSchemaMapper::ARRAY_BASES, true)) {
            return $value;
        }

        return $this->hydrateElements($this->mapper->resolveElementType($node), $value, $label);
    }

    private function hydrateElements(TypeNode $element, mixed $value, string $label): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->hydrateNode($element, $item, \sprintf('%s.%s', $label, $key));
        }

        return $value;
    }

    private function hydrateShape(ArrayShapeNode $node, mixed $value, string $label): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }

        $tail = $value;

        foreach ($node->items as $index => $item) {
            $key = $this->mapper->resolveShapeKey($item) ?? $index;
            unset($tail[$key]);

            if (\array_key_exists($key, $value)) {
                $value[$key] = $this->hydrateNode($item->valueType, $value[$key], \sprintf('%s.%s', $label, $key));
            }
        }

        if (null === $node->unsealedType) {
            return $value;
        }

        foreach ($tail as $key => $entry) {
            $value[$key] = $this->hydrateNode($node->unsealedType->valueType, $entry, \sprintf('%s.%s', $label, $key));
        }

        return $value;
    }

    /**
     * @param class-string<\UnitEnum> $name
     */
    private function hydrateEnum(string $name, mixed $value, string $label): \UnitEnum
    {
        $context = \sprintf('"%s"', $label);

        return is_subclass_of($name, \BackedEnum::class)
            ? $this->resolveBackedCase($name, $value, $context)
            : $this->resolvePureCase($name, $value, $context);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param non-empty-string          $context
     */
    private function resolveBackedCase(string $enum, mixed $value, string $context): \BackedEnum
    {
        /** @var non-empty-list<int|string> $values */
        $values = array_column($enum::cases(), 'value');
        Assert::that($value)->isOneOf($values, \sprintf('%s must be one of {choices}, {value} given.', $context));

        return $enum::from($value);
    }

    /**
     * @param class-string<\UnitEnum> $enum
     * @param non-empty-string        $context
     */
    private function resolvePureCase(string $enum, mixed $value, string $context): \UnitEnum
    {
        if (\is_string($value)) {
            foreach ($enum::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            '%s must be one of [%s], %s given.',
            $context,
            implode(', ', array_map(
                static fn(\UnitEnum $case): string => var_export($case->name, true),
                $enum::cases(),
            )),
            var_export($value, true),
        ));
    }
}
