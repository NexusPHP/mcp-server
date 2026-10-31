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
use Nexus\Mcp\Server\Auth\OperationScopes;
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
    private OperationScopes $operationScopes;

    /**
     * @param array<int|non-empty-string, list<non-empty-string>> $tools           Deprecated, pass `$operationScopes`. Scopes keyed by tool name
     * @param array<int|non-empty-string, list<non-empty-string>> $prompts         Deprecated, pass `$operationScopes`. Scopes keyed by prompt name
     * @param array<int|non-empty-string, list<non-empty-string>> $resources       Deprecated, pass `$operationScopes`. Scopes keyed by resource URI or URI template
     * @param list<non-empty-string>                              $endpointScopes  Scopes that the authentication middleware asks of every request, named in each challenge beside the operation's own
     * @param null|OperationScopes                                $operationScopes The scopes of each tool, prompt and resource, which cannot be combined with `$tools`, `$prompts` or `$resources`
     */
    public function __construct(
        private string $resourceMetadataUrl,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $tools = [],
        array $prompts = [],
        array $resources = [],
        array $endpointScopes = [],
        ?OperationScopes $operationScopes = null,
    ) {
        if (null !== $operationScopes && ([] !== $tools || [] !== $prompts || [] !== $resources)) {
            throw new LogicException('Pass operation scopes either as $operationScopes or as $tools, $prompts and $resources, not both.');
        }

        $this->endpointScopes = $this->buildScopeSet($endpointScopes);
        $this->operationScopes = $operationScopes ?? new OperationScopes($tools, $prompts, $resources);
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
            CallToolRequest::getMethod() => $this->resolveToolScopes($this->readMember($params, 'name')),
            GetPromptRequest::getMethod() => $this->resolvePromptScopes($this->readMember($params, 'name')),
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
            PromptReference::TYPE => $this->resolvePromptScopes($this->readMember($reference, 'name')),
            ResourceTemplateReference::TYPE => $this->resolveResourceScopes([$this->readMember($reference, 'uri')]),
            default => new ScopeSet(),
        };
    }

    private function resolveResourceScopes(mixed $uris): ScopeSet
    {
        $required = new ScopeSet();

        foreach (\is_array($uris) ? $uris : [] as $uri) {
            if (\is_string($uri)) {
                $required = $required->mergeWith($this->operationScopes->forResource($uri));
            }
        }

        return $required;
    }

    private function resolveToolScopes(mixed $name): ScopeSet
    {
        return \is_string($name) ? $this->operationScopes->forTool($name) : new ScopeSet();
    }

    private function resolvePromptScopes(mixed $name): ScopeSet
    {
        return \is_string($name) ? $this->operationScopes->forPrompt($name) : new ScopeSet();
    }

    private function readMember(mixed $container, string $key): mixed
    {
        return \is_array($container) ? $container[$key] ?? null : null;
    }
}
