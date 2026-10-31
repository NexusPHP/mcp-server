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

namespace Nexus\Mcp\Server\Auth;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Core\UriTemplate\Validator;

/**
 * The scopes that a token must hold to use each tool, prompt and resource.
 */
final readonly class OperationScopes
{
    /**
     * @var array<int|string, ScopeSet>
     */
    private array $tools;

    /**
     * @var array<int|string, ScopeSet>
     */
    private array $prompts;

    /**
     * @var array<non-empty-string, ScopeSet>
     */
    private array $resourcesByPattern;

    /**
     * @param array<int|non-empty-string, list<non-empty-string>> $tools     Scopes keyed by tool name
     * @param array<int|non-empty-string, list<non-empty-string>> $prompts   Scopes keyed by prompt name
     * @param array<int|non-empty-string, list<non-empty-string>> $resources Scopes keyed by resource URI or URI template
     */
    public function __construct(array $tools = [], array $prompts = [], array $resources = [])
    {
        $this->tools = $this->buildScopeSets($tools, 'tool');
        $this->prompts = $this->buildScopeSets($prompts, 'prompt');

        $resourcesByPattern = [];

        foreach ($this->buildScopeSets($resources, 'resource') as $template => $scopes) {
            $template = (string) $template;
            Validator::validate($template, 'Operation scope resource');
            $resourcesByPattern[Matcher::compile($template)] = $scopes;
        }

        $this->resourcesByPattern = $resourcesByPattern;
    }

    public function forTool(string $name): ScopeSet
    {
        return $this->tools[$name] ?? new ScopeSet();
    }

    public function forPrompt(string $name): ScopeSet
    {
        return $this->prompts[$name] ?? new ScopeSet();
    }

    /**
     * The scopes of every declared resource whose URI template matches `$uri`, in its percent-encoded or decoded form.
     */
    public function forResource(string $uri): ScopeSet
    {
        $required = new ScopeSet();

        foreach ($this->resourcesByPattern as $pattern => $scopes) {
            // A server template binds a percent-encoded URI to its decoded value, so both forms are matched.
            if (Matcher::matchCompiled($pattern, $uri) !== null || Matcher::matchCompiled($pattern, rawurldecode($uri)) !== null) {
                $required = $required->mergeWith($scopes);
            }
        }

        return $required;
    }

    /**
     * @param array<int|non-empty-string, list<non-empty-string>> $scopesByName
     *
     * @return array<int|non-empty-string, ScopeSet>
     */
    private function buildScopeSets(array $scopesByName, string $kind): array
    {
        Assert::that($scopesByName)->keys()->isIntOrNonEmptyString(\sprintf(
            'each operation scope %s key must be an int or non-empty string, {value} given.',
            $kind,
        ));
        Assert::that($scopesByName)->values()->isList(\sprintf(
            'each operation scope %s entry must be a list of scopes, {type} given.',
            $kind,
        ));

        return array_map($this->buildScopeSet(...), $scopesByName);
    }

    /**
     * @param list<non-empty-string> $scopes
     */
    private function buildScopeSet(array $scopes): ScopeSet
    {
        Assert::that($scopes)->values()->matchesRegularExpression(
            ScopeSet::SCOPE_TOKEN_PATTERN,
            'each operation scope must be an RFC 6749 scope-token, {value} given.',
        );

        return new ScopeSet($scopes);
    }
}
