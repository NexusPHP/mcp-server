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
 * A request presenting no bearer credential is answered `401` with a `WWW-Authenticate` challenge naming the
 * Protected Resource Metadata document, one presenting a bearer credential that cannot be read is answered
 * `400 invalid_request`, and a token that is valid but too narrow is answered `403 insufficient_scope`. The
 * validated token reaches request handlers on `ServerContext::$receiveContext->authInfo`.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#error-handling
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

        // RFC 6750 puts a request that presented no bearer credential, whether it carried nothing at all or
        // tried an authentication method this server does not support, in one bucket, answered with a bare
        // challenge and no error code. Only a bearer credential that cannot be read gets one.
        if (! self::presentsBearerScheme($headers)) {
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
     * Whether any header names the bearer scheme, which is what tells a malformed bearer credential apart
     * from a request that presented none.
     *
     * @param array<array-key, string> $headers
     *
     * @phpstan-assert-if-true non-empty-array<array-key, string> $headers
     */
    private static function presentsBearerScheme(array $headers): bool
    {
        foreach ($headers as $header) {
            // RFC 7235 makes the scheme case-insensitive and separates it from the credential by a space.
            if (strcasecmp(explode(' ', $header)[0], WwwAuthenticateChallenge::BEARER_SCHEME) === 0) {
                return true;
            }
        }

        return false;
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

        // The scheme was matched case-insensitively before this, so only what follows it is read, and that
        // is compared as sent.
        $token = trim(substr(reset($headers), \strlen(self::BEARER_PREFIX)));

        return '' === $token ? null : $token;
    }
}
