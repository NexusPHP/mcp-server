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

/**
 * Binds a named value map to a handler method's parameters.
 *
 * @internal
 */
final class ArgumentBinder
{
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
     * Whether `$value` is the `null` a nullable parameter's advertised schema permits.
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

        if (! $type instanceof \ReflectionNamedType) {
            return $value;
        }

        $label = null === $scope
            ? $parameter->getName()
            : \sprintf('%s.%s', $scope, $parameter->getName());
        $name = $type->getName();

        if (\in_array(strtolower($name), ['object', 'stdclass'], true)) {
            if (\is_object($value)) {
                return $value;
            }

            Assert::that($value)->isMap(\sprintf('"%s" must be an object, {type} given.', $label));

            return (object) $value;
        }

        if ($type->isBuiltin() || ! enum_exists($name)) {
            return $value;
        }

        $context = \sprintf('"%s"', $label);

        if (is_subclass_of($name, \BackedEnum::class)) {
            /** @var non-empty-list<int|string> $values */
            $values = array_column($name::cases(), 'value');
            Assert::that($value)->isOneOf($values, \sprintf('%s must be one of {choices}, {value} given.', $context));

            return $name::from($value);
        }

        return $this->resolvePureCase($name, $value, $context);
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
