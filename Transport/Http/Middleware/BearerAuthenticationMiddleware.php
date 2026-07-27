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

use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes the MCP endpoint an OAuth 2.1 resource server: it requires a bearer token, binds that token's audience
 * to this server, and enforces the scopes the endpoint calls for.
 *
 * A request carrying no credentials is answered `401` with a `WWW-Authenticate` challenge naming the Protected
 * Resource Metadata document, one whose `Authorization` header cannot be read is answered `400 invalid_request`,
 * and a token that is valid but too narrow is answered `403 insufficient_scope`. The validated token reaches
 * request handlers on `ServerContext::$receiveContext->authInfo`.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/authorization#error-handling
 */
final readonly class BearerAuthenticationMiddleware implements MiddlewareInterface
{
    private const string BEARER_PREFIX = WwwAuthenticateChallenge::BEARER_SCHEME.' ';

    private ResourceIdentifier $resource;
    private ScopeSet $requiredScopes;

    /**
     * @param string                 $resource            Canonical URI of this MCP server, which a token's audience must name
     * @param string                 $resourceMetadataUrl URL of this server's Protected Resource Metadata document
     * @param list<non-empty-string> $requiredScopes      Scopes every request to the endpoint must carry
     */
    public function __construct(
        private AccessTokenValidatorInterface $validator,
        string $resource,
        private string $resourceMetadataUrl,
        private ResponseFactoryInterface $responseFactory,
        array $requiredScopes = [],
    ) {
        $this->resource = new ResourceIdentifier($resource);
        $this->requiredScopes = new ScopeSet($requiredScopes);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headers = $request->getHeader('Authorization');

        // RFC 6750 tells a request carrying no credentials apart from one whose credentials cannot be read:
        // the first is answered with a bare challenge, the second names what was wrong with it.
        if ([] === $headers) {
            return $this->challenge(HttpStatus::Unauthorized, null);
        }

        $presented = self::readBearerToken($headers);

        if (null === $presented) {
            return $this->challenge(HttpStatus::BadRequest, 'invalid_request');
        }

        $token = $this->validator->validate($presented);

        if (null === $token) {
            return $this->challenge(HttpStatus::Unauthorized, 'invalid_token');
        }

        // A token minted for a different resource must never be accepted here, nor passed further on.
        if (! $this->resource->matchesAudience($token->audience)) {
            return $this->challenge(HttpStatus::Unauthorized, 'invalid_token');
        }

        if (! new ScopeSet($token->scopes)->containsAll($this->requiredScopes)) {
            return $this->challenge(HttpStatus::Forbidden, 'insufficient_scope');
        }

        return $handler->handle($request->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token));
    }

    private function challenge(HttpStatus $status, ?string $error): ResponseInterface
    {
        $parameters = ['resource_metadata' => $this->resourceMetadataUrl];

        if (null !== $error) {
            $parameters['error'] = $error;
        }

        $scope = $this->requiredScopes->toParameter();

        if (null !== $scope) {
            $parameters['scope'] = $scope;
        }

        return $this->responseFactory->createResponse($status->value)
            ->withHeader('WWW-Authenticate', new WwwAuthenticateChallenge(
                WwwAuthenticateChallenge::BEARER_SCHEME,
                $parameters,
            )->toHeaderValue())
        ;
    }

    /**
     * @param non-empty-array<array-key, string> $headers
     */
    private static function readBearerToken(array $headers): ?string
    {
        // RFC 7235 permits exactly one. Several would be joined into one string, smuggling a second
        // credential past a lenient validator.
        if (\count($headers) !== 1) {
            return null;
        }

        $header = reset($headers);

        // RFC 7235 makes the scheme case-insensitive, so only the token that follows it is compared as sent.
        if (! str_starts_with(strtolower($header), strtolower(self::BEARER_PREFIX))) {
            return null;
        }

        $token = trim(substr($header, \strlen(self::BEARER_PREFIX)));

        return '' === $token ? null : $token;
    }
}
