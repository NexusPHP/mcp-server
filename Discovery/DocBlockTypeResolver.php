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

use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Parses method docblocks and native type strings into phpdoc-parser type nodes.
 *
 * @internal
 */
final class DocBlockTypeResolver
{
    private Lexer $lexer;
    private TypeParser $typeParser;
    private PhpDocParser $phpDocParser;
    private DocBlockNameResolver $names;

    /**
     * @var array<string, array<string, ParamTagValueNode>>
     */
    private array $tags = [];

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $this->typeParser, $constExprParser);
        $this->names = new DocBlockNameResolver();
    }

    public function parseNativeType(string $type): TypeNode
    {
        return $this->typeParser->parse(new TokenIterator($this->lexer->tokenize($type)));
    }

    /**
     * Returns the `@param` tags keyed by parameter name without the leading `$`, with the `@var` of a constructor's
     * promoted properties standing in for the parameters that carry no `@param`.
     *
     * @return array<string, ParamTagValueNode>
     */
    public function parseParamTags(\ReflectionFunctionAbstract $method): array
    {
        $key = \sprintf(
            '%s:%s:%s',
            (string) $method->getFileName(),
            (string) $method->getStartLine(),
            $method->getName(),
        );

        $this->tags[$key] ??= $this->readParamTags($method) + $this->readPromotedVarTags($method);

        return $this->tags[$key];
    }

    /**
     * @return array<string, ParamTagValueNode>
     */
    private function readParamTags(\ReflectionFunctionAbstract $method): array
    {
        $docComment = $method->getDocComment();

        if (false === $docComment) {
            return [];
        }

        $tags = [];

        foreach ($this->parseDocBlock($docComment)->getParamTagValues() as $tag) {
            $this->names->qualify($tag->type, $method);
            $tags[ltrim($tag->parameterName, '$')] = $tag;
        }

        return $tags;
    }

    /**
     * @return array<string, ParamTagValueNode>
     */
    private function readPromotedVarTags(\ReflectionFunctionAbstract $method): array
    {
        if (! $method instanceof \ReflectionMethod) {
            return [];
        }

        $tags = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $docComment = $parameter->isPromoted()
                ? $method->getDeclaringClass()->getProperty($name)->getDocComment()
                : false;

            if (false === $docComment) {
                continue;
            }

            foreach ($this->parseDocBlock($docComment)->getVarTagValues() as $var) {
                $this->names->qualify($var->type, $method);
                $tags[$name] = new ParamTagValueNode($var->type, false, '$'.$name, $var->description, false);
            }
        }

        return $tags;
    }

    private function parseDocBlock(string $docComment): PhpDocNode
    {
        return $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
    }
}
