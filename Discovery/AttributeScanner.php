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

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Core\Schema\Tool\ToolExecution;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Exception\UnsupportedParameterTypeException;
use Nexus\Mcp\Server\Exception\UnsupportedVariadicParameterException;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\ReflectedPromptRenderer;
use Nexus\Mcp\Server\Resource\ReflectedResourceReader;
use Nexus\Mcp\Server\Resource\ReflectedTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Tool\ReflectedToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;

/**
 * Reflects a source object's attribute-marked methods into the per-feature entries
 * consumed by `ServerBuilder::register()`.
 *
 * @internal
 */
final readonly class AttributeScanner
{
    public function __construct(private InputSchemaGenerator $schemaGenerator = new InputSchemaGenerator(), private DocBlockTypeResolver $resolver = new DocBlockTypeResolver())
    {
    }

    /**
     * @return iterable<PromptEntry|ResourceEntry|ResourceTemplateEntry|ToolEntry>
     */
    public function scan(object $source): iterable
    {
        foreach (new \ReflectionObject($source)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsTool::class) as $attribute) {
                yield new ToolEntry(
                    $this->buildTool($method, $attribute->newInstance()),
                    new ReflectedToolExecutor($source, $method),
                );
            }

            foreach ($method->getAttributes(AsPrompt::class) as $attribute) {
                yield new PromptEntry(
                    $this->buildPrompt($method, $attribute->newInstance()),
                    new ReflectedPromptRenderer($source, $method),
                );
            }

            foreach ($method->getAttributes(AsResource::class) as $attribute) {
                yield new ResourceEntry(
                    self::buildResource($method, $attribute->newInstance()),
                    new ReflectedResourceReader($source, $method),
                );
            }

            foreach ($method->getAttributes(AsResourceTemplate::class) as $attribute) {
                yield new ResourceTemplateEntry(
                    self::buildResourceTemplate($method, $attribute->newInstance()),
                    new ReflectedTemplatedResourceReader($source, $method),
                );
            }
        }
    }

    private function buildTool(\ReflectionMethod $method, AsTool $attribute): Tool
    {
        return new Tool(
            $attribute->name ?? $method->getName(),
            $this->schemaGenerator->generate($method),
            $attribute->title,
            $attribute->description,
            $attribute->outputSchema,
            $attribute->annotations ?? new ToolAnnotations(),
            $attribute->execution ?? new ToolExecution(),
            $attribute->icons,
            new MetaObject($attribute->meta ?? []),
        );
    }

    private function buildPrompt(\ReflectionMethod $method, AsPrompt $attribute): Prompt
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new Prompt(
            $attribute->name ?? $method->getName(),
            $attribute->title,
            $attribute->description,
            $this->buildPromptArguments($method),
            $attribute->icons,
            new MetaObject($attribute->meta ?? []),
        );
    }

    /**
     * @return null|list<PromptArgument>
     */
    private function buildPromptArguments(\ReflectionMethod $method): ?array
    {
        $tags = $this->resolver->parseParamTags($method);
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            if (InputSchemaGenerator::isInjectedContext($parameter)) {
                continue;
            }

            $description = $tags[$parameter->getName()]->description ?? '';

            $arguments[] = new PromptArgument(
                $parameter->getName(),
                null,
                '' === $description ? null : $description,
                ! $parameter->isOptional(),
            );
        }

        return [] === $arguments ? null : $arguments;
    }

    private static function buildResource(\ReflectionMethod $method, AsResource $attribute): Resource
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new Resource(
            $attribute->name ?? $method->getName(),
            $attribute->uri,
            $attribute->title,
            $attribute->description,
            $attribute->mimeType,
            $attribute->annotations ?? new Annotations(),
            $attribute->size,
            $attribute->icons,
            new MetaObject($attribute->meta ?? []),
        );
    }

    private static function buildResourceTemplate(\ReflectionMethod $method, AsResourceTemplate $attribute): ResourceTemplate
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new ResourceTemplate(
            $attribute->name ?? $method->getName(),
            $attribute->uriTemplate,
            $attribute->title,
            $attribute->description,
            $attribute->mimeType,
            $attribute->annotations ?? new Annotations(),
            $attribute->icons,
            new MetaObject($attribute->meta ?? []),
        );
    }

    /**
     * @throws UnsupportedParameterTypeException
     */
    private static function rejectUnsupportedParameterType(\ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            if (InputSchemaGenerator::isInjectedContext($parameter) || self::acceptsStringArgument($parameter->getType())) {
                continue;
            }

            throw new UnsupportedParameterTypeException(
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $parameter->getName(),
                (string) $parameter->getType(),
            );
        }
    }

    private static function acceptsStringArgument(?\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                static fn(\ReflectionType $member): bool => self::acceptsStringArgument($member),
            );
        }

        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin()
                ? $type->getName() === 'string' || $type->getName() === 'mixed'
                : self::isStringResolvableEnum($type->getName());
        }

        // Untyped accepts the string verbatim. An intersection type cannot be a string.
        return ! $type instanceof \ReflectionType;
    }

    private static function isStringResolvableEnum(string $name): bool
    {
        if (! enum_exists($name)) {
            return false;
        }

        $backingType = new \ReflectionEnum($name)->getBackingType();

        return ! $backingType instanceof \ReflectionNamedType || $backingType->getName() === 'string';
    }

    /**
     * @throws UnsupportedVariadicParameterException
     */
    private static function rejectIfVariadic(\ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                throw new UnsupportedVariadicParameterException(
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $parameter->getName(),
                );
            }
        }
    }
}
