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
 * Makes the MCP endpoint an OAuth 2.1 resource server, handing what it validated to handlers on
 * `ServerContext::$receiveContext->authInfo`.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#error-handling
 */
final readonly class BearerAuthenticationMiddleware implements MiddlewareInterface
{
    private const string BEARER_PREFIX = WwwAuthenticateChallenge::BEARER_SCHEME.' ';

    private ResourceIdentifier $resource;
    private ScopeSet $requiredScopes;

    /**
     * @var \Closure(): int
     */
    private \Closure $clock;

    /**
     * @param string                 $resource            Canonical URI of this MCP server, which a token's audience must name
     * @param list<non-empty-string> $requiredScopes
     * @param int<0, max>            $expiryLeewaySeconds
     * @param null|\Closure(): int   $clock               Reads the current Unix timestamp
     */
    public function __construct(
        private AccessTokenValidatorInterface $validator,
        string $resource,
        private string $resourceMetadataUrl,
        private ResponseFactoryInterface $responseFactory,
        array $requiredScopes = [],
        private int $expiryLeewaySeconds = 0,
        ?\Closure $clock = null,
    ) {
        Assert::that($expiryLeewaySeconds)->isNaturalInt('Expiry leeway must be a non-negative integer, {value} given.');

        $this->resource = new ResourceIdentifier($resource);
        $this->requiredScopes = new ScopeSet($requiredScopes);
        $this->clock = $clock ?? static fn(): int => time();
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headers = $request->getHeader('Authorization');

        // RFC 6750 answers any request presenting no bearer credential with a bare challenge and no error code.
        if (! $this->presentsBearerScheme($headers)) {
            return $this->challenge(HttpStatus::Unauthorized, null);
        }

        $presented = $this->readBearerToken($headers);

        if (null === $presented) {
            return $this->challenge(HttpStatus::BadRequest, 'invalid_request');
        }

        $token = $this->validator->validate($presented);

        if (null === $token) {
            return $this->challenge(HttpStatus::Unauthorized, 'invalid_token');
        }

        if (null !== $token->expiresAt && ($this->clock)() >= $token->expiresAt + $this->expiryLeewaySeconds) {
            return $this->challenge(HttpStatus::Unauthorized, 'invalid_token');
        }

        if (! $this->resource->matchesAudience($token->audience)) {
            return $this->challenge(HttpStatus::Unauthorized, 'invalid_token');
        }

        if (! (new ScopeSet($token->scopes))->containsAll($this->requiredScopes)) {
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
            ->withHeader('WWW-Authenticate', (new WwwAuthenticateChallenge(
                WwwAuthenticateChallenge::BEARER_SCHEME,
                $parameters,
            ))->toHeaderValue())
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
    private function presentsBearerScheme(array $headers): bool
    {
        foreach ($headers as $header) {
            if (strcasecmp(explode(' ', $header)[0], WwwAuthenticateChallenge::BEARER_SCHEME) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-array<array-key, string> $headers
     */
    private function readBearerToken(array $headers): ?string
    {
        // RFC 7235 permits exactly one, and several joined into one string could smuggle a credential past a lenient validator.
        if (\count($headers) !== 1) {
            return null;
        }

        $token = trim(substr(reset($headers), \strlen(self::BEARER_PREFIX)));

        return '' === $token ? null : $token;
    }
}
