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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
use Nexus\Mcp\Server\ServerContext;

/**
 * Binds a named value map to a handler method's parameters, injecting the `ServerContext` and hydrating enums.
 *
 * @internal
 */
final class ArgumentBinder
{
    /**
     * @param array<string, mixed> $values
     *
     * @return list<mixed>
     *
     * @throws ExpectationFailedException
     */
    public function bind(\ReflectionMethod $method, array $values, ServerContext $context): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (self::isContext($parameter)) {
                $arguments[] = $context;
            } elseif ($parameter->isVariadic()) {
                $list = $values[$name] ?? [];
                Assert::that($list)->isList(\sprintf('The "%s" argument must be a list, {type} given.', $name));

                foreach ($list as $element) {
                    $arguments[] = self::bindArgument($parameter, $element);
                }
            } elseif (\array_key_exists($name, $values)) {
                $arguments[] = self::bindArgument($parameter, $values[$name]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new ExpectationFailedException('The "{name}" argument is required.', ['name' => $name]);
            }
        }

        return $arguments;
    }

    private static function bindArgument(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $class = self::dtoClass($parameter);

        return null !== $class ? self::construct($class, $value) : self::hydrate($parameter, $value);
    }

    /**
     * @param class-string $class
     *
     * @throws ExpectationFailedException
     */
    private static function construct(string $class, mixed $value): object
    {
        Assert::that($value)->isMap(\sprintf('%s must be constructed from an object, {type} given.', $class));

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (\array_key_exists($name, $value)) {
                $arguments[] = self::hydrate($parameter, $value[$name]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new ExpectationFailedException('The "{name}" argument is required.', ['name' => $name]);
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @return null|class-string
     */
    private static function dtoClass(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return InputSchemaGenerator::isExpandable($name) ? $name : null;
    }

    private static function isContext(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && ServerContext::class === $type->getName();
    }

    private static function hydrate(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $name = $type->getName();

        if (! enum_exists($name)) {
            return $value;
        }

        $context = \sprintf('Parameter "$%s"', $parameter->getName());

        if (is_subclass_of($name, \BackedEnum::class)) {
            return EnumValueValidator::parse($name, $value, $context);
        }

        return self::pureCase($name, $value, $context);
    }

    /**
     * @param class-string<\UnitEnum> $enum
     * @param non-empty-string        $context
     */
    private static function pureCase(string $enum, mixed $value, string $context): \UnitEnum
    {
        if (\is_string($value)) {
            foreach ($enum::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
        }

        throw new ExpectationFailedException(
            '{context} must be one of [{cases}], {value} given.',
            [
                'context' => $context,
                'cases' => implode(', ', array_map(
                    static fn(\UnitEnum $case): string => var_export($case->name, true),
                    $enum::cases(),
                )),
                'value' => var_export($value, true),
            ],
        );
    }
}
