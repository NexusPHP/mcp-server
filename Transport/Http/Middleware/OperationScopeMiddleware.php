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

namespace Nexus\Mcp\Server\Transport\Http\Middleware;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Challenges a request for a tool, prompt, or resource whose token lacks the scopes declared for it.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#runtime-insufficient-scope-errors
 */
final readonly class OperationScopeMiddleware implements MiddlewareInterface
{
    private ScopeSet $endpointScopes;

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
     * @param array<int|non-empty-string, list<non-empty-string>> $tools          Scopes keyed by tool name
     * @param array<int|non-empty-string, list<non-empty-string>> $prompts        Scopes keyed by prompt name
     * @param array<int|non-empty-string, list<non-empty-string>> $resources      Scopes keyed by resource URI or URI template
     * @param list<non-empty-string>                              $endpointScopes Scopes that the authentication middleware asks of every request, named in each challenge beside the operation's own
     */
    public function __construct(
        private string $resourceMetadataUrl,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $tools = [],
        array $prompts = [],
        array $resources = [],
        array $endpointScopes = [],
    ) {
        $this->endpointScopes = $this->buildScopeSet($endpointScopes);
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

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (string) $request->getBody();
        $request = $request->withBody($this->streamFactory->createStream($body));
        $envelope = json_decode($body, associative: true);

        if (\is_array($envelope)) {
            $request = $request->withAttribute(StreamableHttpServerTransport::ENVELOPE_ATTRIBUTE, $envelope);
        }

        $operationScopes = $this->resolveOperationScopes($envelope);

        if ([] === $operationScopes->values) {
            return $handler->handle($request);
        }

        $token = $request->getAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE);

        if (! $token instanceof VerifiedAccessToken) {
            throw new LogicException(\sprintf(
                'Operation scopes need the validated token an authentication middleware stores on the "%s" request attribute, and none was found.',
                VerifiedAccessToken::REQUEST_ATTRIBUTE,
            ));
        }

        $required = $this->endpointScopes->mergeWith($operationScopes);

        if ((new ScopeSet($token->scopes))->containsAll($required)) {
            return $handler->handle($request);
        }

        return $this->responseFactory->createResponse(HttpStatus::Forbidden->value)->withHeader(
            'WWW-Authenticate',
            WwwAuthenticateChallenge::buildForResource($this->resourceMetadataUrl, 'insufficient_scope', $required)->toHeaderValue(),
        );
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

    private function resolveOperationScopes(mixed $envelope): ScopeSet
    {
        $params = $this->readMember($envelope, 'params');

        return match ($this->readMember($envelope, 'method')) {
            CallToolRequest::getMethod() => $this->lookUp($this->tools, $this->readMember($params, 'name')),
            GetPromptRequest::getMethod() => $this->lookUp($this->prompts, $this->readMember($params, 'name')),
            ReadResourceRequest::getMethod() => $this->resolveResourceScopes([$this->readMember($params, 'uri')]),
            CompleteRequest::getMethod() => $this->resolveReferenceScopes($this->readMember($params, 'ref')),
            SubscriptionsListenRequest::getMethod() => $this->resolveResourceScopes(
                $this->readMember($this->readMember($params, 'notifications'), 'resourceSubscriptions'),
            ),
            default => new ScopeSet(),
        };
    }

    private function resolveReferenceScopes(mixed $reference): ScopeSet
    {
        return match ($this->readMember($reference, 'type')) {
            PromptReference::TYPE => $this->lookUp($this->prompts, $this->readMember($reference, 'name')),
            ResourceTemplateReference::TYPE => $this->resolveResourceScopes([$this->readMember($reference, 'uri')]),
            default => new ScopeSet(),
        };
    }

    private function resolveResourceScopes(mixed $uris): ScopeSet
    {
        $required = new ScopeSet();

        foreach (\is_array($uris) ? $uris : [] as $uri) {
            if (! \is_string($uri)) {
                continue;
            }

            foreach ($this->resourcesByPattern as $pattern => $scopes) {
                // A server template binds a percent-encoded URI to its decoded value, so both forms are matched.
                if (Matcher::matchCompiled($pattern, $uri) !== null || Matcher::matchCompiled($pattern, rawurldecode($uri)) !== null) {
                    $required = $required->mergeWith($scopes);
                }
            }
        }

        return $required;
    }

    /**
     * @param array<int|string, ScopeSet> $scopesByName
     */
    private function lookUp(array $scopesByName, mixed $name): ScopeSet
    {
        return \is_string($name) ? $scopesByName[$name] ?? new ScopeSet() : new ScopeSet();
    }

    private function readMember(mixed $container, string $key): mixed
    {
        return \is_array($container) ? $container[$key] ?? null : null;
    }
}
