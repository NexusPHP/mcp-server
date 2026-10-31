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

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * Qualifies the class names in a docblock type against the namespace and imports of the file declaring it.
 *
 * @internal
 */
final class DocBlockNameResolver
{
    /**
     * @var array<string, non-empty-list<array{line: int, namespace: string, imports: array<string, string>}>>
     */
    private array $scopes = [];

    /**
     * Rewrites in place each identifier of `$type` that names a class or interface once qualified.
     */
    public function qualify(TypeNode $type, \ReflectionFunctionAbstract $function): void
    {
        $file = $function->getFileName();
        $line = $function->getStartLine();
        \assert(\is_string($file));
        \assert(\is_int($line));

        $this->scopes[$file] ??= $this->readScopes(is_readable($file) ? (string) file_get_contents($file) : '');
        $scope = $this->scopes[$file][0];

        foreach ($this->scopes[$file] as $candidate) {
            if ($candidate['line'] <= $line) {
                $scope = $candidate;
            }
        }

        $visitor = new class ($scope['namespace'], $scope['imports']) extends AbstractNodeVisitor {
            /**
             * @var array<int, true>
             */
            private array $shapeKeys = [];

            /**
             * @param array<string, string> $imports
             */
            public function __construct(
                private readonly string $namespace,
                private readonly array $imports,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) {
                    if (null !== $node->keyName) {
                        $this->shapeKeys[spl_object_id($node->keyName)] = true;
                    }
                } elseif ($node instanceof IdentifierTypeNode && ! isset($this->shapeKeys[spl_object_id($node)])) {
                    $node->name = $this->qualifyName($node->name);
                }

                return null;
            }

            private function qualifyName(string $name): string
            {
                $separator = strpos($name, '\\');
                $first = false === $separator ? $name : substr($name, 0, $separator);
                $rest = false === $separator ? '' : substr($name, $separator);
                $qualified = isset($this->imports[strtolower($first)])
                    ? $this->imports[strtolower($first)].$rest
                    : ('' === $this->namespace ? $name : $this->namespace.'\\'.$name);

                return class_exists($qualified) || interface_exists($qualified) ? $qualified : $name;
            }
        };

        (new NodeTraverser([$visitor]))->traverse([$type]);
    }

    /**
     * @return non-empty-list<array{line: int, namespace: string, imports: array<string, string>}>
     */
    private function readScopes(string $code): array
    {
        $scopes = [['line' => \PHP_INT_MIN, 'namespace' => '', 'imports' => []]];
        $nesting = 0;
        $statement = null;
        $atStatementStart = true;

        foreach (token_get_all($code) as $token) {
            [$id, $text] = \is_array($token) ? $token : [null, $token];

            if (\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG], true)) {
                continue;
            }

            $symbol = \T_CURLY_OPEN === $id ? '{' : (\is_array($token) ? '' : $token);
            $last = array_key_last($scopes);

            if (null !== $statement) {
                if (';' === $symbol || ('namespace' === $statement['kind'] && '{' === $symbol)) {
                    if ('namespace' === $statement['kind']) {
                        $scopes[$last]['namespace'] = implode('', $statement['parts']);
                        $nesting = 0;
                    } else {
                        $scopes[$last]['imports'] += $this->parseUse(implode(' ', $statement['parts']));
                    }

                    $statement = null;
                } else {
                    $statement['parts'][] = $text;
                }
            } elseif ('{' === $symbol) {
                ++$nesting;
            } elseif ('}' === $symbol) {
                --$nesting;
            } elseif (\T_NAMESPACE === $id && \is_array($token)) {
                $scopes[] = ['line' => $token[2], 'namespace' => '', 'imports' => []];
                $statement = ['kind' => 'namespace', 'parts' => []];
            } elseif (\T_USE === $id && 0 === $nesting && $atStatementStart) {
                $statement = ['kind' => 'use', 'parts' => []];
            }

            $atStatementStart = \in_array($symbol, [';', '{', '}'], true);
        }

        return $scopes;
    }

    /**
     * @return array<string, string>
     */
    private function parseUse(string $text): array
    {
        $prefix = '';

        if (preg_match('/(.*\\\\)\s*\{(.*)\}/', $text, $group) === 1) {
            $prefix = str_replace(' ', '', $group[1]);
            $text = $group[2];
        }

        $imports = [];

        foreach (explode(',', $text) as $clause) {
            $clause = trim($clause);
            $alias = stripos($clause, ' as ');
            $name = false === $alias ? $clause : substr($clause, 0, $alias);
            $imports[strtolower(basename(strtr($clause, '\\ ', '//')))] = ltrim($prefix.$name, '\\');
        }

        return $imports;
    }
}
