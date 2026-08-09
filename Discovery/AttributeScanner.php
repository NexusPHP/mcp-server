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
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Server\Attribute\AsCompletion;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Completion\PromptCompletionEntry;
use Nexus\Mcp\Server\Completion\ReflectedCompletionProvider;
use Nexus\Mcp\Server\Completion\ResourceTemplateCompletionEntry;
use Nexus\Mcp\Server\Exception\InvalidCompletionAttributeException;
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
     * @return iterable<PromptCompletionEntry|PromptEntry|ResourceEntry|ResourceTemplateCompletionEntry|ResourceTemplateEntry|ToolEntry>
     */
    public function scan(object $source): iterable
    {
        foreach ((new \ReflectionObject($source))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
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

            foreach ($method->getAttributes(AsCompletion::class) as $attribute) {
                yield self::buildCompletion($source, $method, $attribute->newInstance());
            }
        }
    }

    /**
     * @throws InvalidCompletionAttributeException
     */
    private static function buildCompletion(
        object $source,
        \ReflectionMethod $method,
        AsCompletion $attribute,
    ): PromptCompletionEntry|ResourceTemplateCompletionEntry {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedCompletionParameterType($method);

        $class = $method->getDeclaringClass()->getName();
        $name = $method->getName();
        $argument = $attribute->argument;
        $prompt = $attribute->prompt;
        $uriTemplate = $attribute->uriTemplate;

        if (null !== $prompt && null !== $uriTemplate) {
            throw new InvalidCompletionAttributeException($class, $name, 'it must name either a "prompt" or a "uriTemplate", not both');
        }

        if ('' === $argument) {
            throw new InvalidCompletionAttributeException($class, $name, 'its "argument" must be a non-empty string');
        }

        $provider = new ReflectedCompletionProvider($source, $method);

        if (null !== $prompt && '' !== $prompt) {
            return new PromptCompletionEntry($prompt, $argument, $provider);
        }

        if (null !== $uriTemplate && '' !== $uriTemplate) {
            return new ResourceTemplateCompletionEntry($uriTemplate, $argument, $provider);
        }

        throw new InvalidCompletionAttributeException($class, $name, 'it must name the completed "prompt" or "uriTemplate"');
    }

    /**
     * A completion parameter is an injected `ServerContext`, the context-arguments `array` slot, or
     * a value slot taking the raw partial string, with no enum coercion on this path.
     *
     * @throws UnsupportedParameterTypeException
     */
    private static function rejectUnsupportedCompletionParameterType(\ReflectionMethod $method): void
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (InputSchemaGenerator::isInjectedContext($parameter)
                || ($type instanceof \ReflectionNamedType && $type->getName() === 'array')
                || self::acceptsRawStringArgument($type)
            ) {
                continue;
            }

            throw new UnsupportedParameterTypeException(
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $parameter->getName(),
                (string) $type,
            );
        }
    }

    private static function acceptsRawStringArgument(?\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::acceptsRawStringArgument($member)) {
                    return true;
                }
            }
        } elseif ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() && ($type->getName() === 'string' || $type->getName() === 'mixed');
        }

        return ! $type instanceof \ReflectionType;
    }

    private function buildTool(\ReflectionMethod $method, AsTool $attribute): Tool
    {
        return new Tool(
            name: $attribute->name ?? $method->getName(),
            inputSchema: $this->schemaGenerator->generate($method),
            title: $attribute->title,
            description: $attribute->description,
            outputSchema: $attribute->outputSchema,
            annotations: $attribute->annotations ?? new ToolAnnotations(),
            icons: $attribute->icons,
            meta: new PayloadMetaObject(extras: $attribute->meta ?? []),
        );
    }

    private function buildPrompt(\ReflectionMethod $method, AsPrompt $attribute): Prompt
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new Prompt(
            name: $attribute->name ?? $method->getName(),
            title: $attribute->title,
            description: $attribute->description,
            arguments: $this->buildPromptArguments($method),
            icons: $attribute->icons,
            meta: new PayloadMetaObject(extras: $attribute->meta ?? []),
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
                name: $parameter->getName(),
                description: '' === $description ? null : $description,
                required: ! $parameter->isOptional(),
            );
        }

        return [] === $arguments ? null : $arguments;
    }

    private static function buildResource(\ReflectionMethod $method, AsResource $attribute): Resource
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new Resource(
            name: $attribute->name ?? $method->getName(),
            uri: $attribute->uri,
            title: $attribute->title,
            description: $attribute->description,
            mimeType: $attribute->mimeType,
            annotations: $attribute->annotations ?? new Annotations(),
            size: $attribute->size,
            icons: $attribute->icons,
            meta: new PayloadMetaObject(extras: $attribute->meta ?? []),
        );
    }

    private static function buildResourceTemplate(\ReflectionMethod $method, AsResourceTemplate $attribute): ResourceTemplate
    {
        self::rejectIfVariadic($method);
        self::rejectUnsupportedParameterType($method);

        return new ResourceTemplate(
            name: $attribute->name ?? $method->getName(),
            uriTemplate: $attribute->uriTemplate,
            title: $attribute->title,
            description: $attribute->description,
            mimeType: $attribute->mimeType,
            annotations: $attribute->annotations ?? new Annotations(),
            icons: $attribute->icons,
            meta: new PayloadMetaObject(extras: $attribute->meta ?? []),
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
            foreach ($type->getTypes() as $member) {
                if (self::acceptsStringArgument($member)) {
                    return true;
                }
            }
        } elseif ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin()
                ? $type->getName() === 'string' || $type->getName() === 'mixed'
                : self::isStringResolvableEnum($type->getName());
        }

        return ! $type instanceof \ReflectionType;
    }

    private static function isStringResolvableEnum(string $name): bool
    {
        if (! enum_exists($name)) {
            return false;
        }

        $backingType = (new \ReflectionEnum($name))->getBackingType();

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
