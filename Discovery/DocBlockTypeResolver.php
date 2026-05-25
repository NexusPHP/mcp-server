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

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $this->typeParser, $constExprParser);
    }

    public function parseNativeType(string $type): TypeNode
    {
        return $this->typeParser->parse(new TokenIterator($this->lexer->tokenize($type)));
    }

    /**
     * Returns the `@param` tags keyed by parameter name without the leading `$`.
     *
     * @return array<string, ParamTagValueNode>
     */
    public function paramTags(\ReflectionFunctionAbstract $method): array
    {
        $docComment = $method->getDocComment();

        if (false === $docComment) {
            return [];
        }

        $node = $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($docComment)));

        $tags = [];

        foreach ($node->getParamTagValues() as $tag) {
            $tags[ltrim($tag->parameterName, '$')] = $tag;
        }

        return $tags;
    }
}
