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

namespace Nexus\Mcp\Server\Transport\Http;

use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Http\HttpStatus;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves this MCP server's Protected Resource Metadata document, the record a client reads to learn which
 * authorization servers issue tokens for it.
 *
 * Route it at both `/.well-known/oauth-protected-resource{/path}` and `/.well-known/oauth-protected-resource`,
 * and name the same URL in `BearerAuthenticationMiddleware`'s challenges.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728#section-3
 */
final readonly class ProtectedResourceMetadataHandler implements RequestHandlerInterface
{
    /**
     * MCP requires the token in the `Authorization` header and forbids it in the query string.
     */
    private const string BEARER_METHOD_HEADER = 'header';

    private ProtectedResourceMetadata $document;

    /**
     * @param string                 $resource             Canonical URI of this MCP server
     * @param list<non-empty-string> $authorizationServers Issuers that mint tokens for it, at least one
     * @param list<non-empty-string> $scopesSupported      Scopes basic use of this server calls for
     * @param ?non-empty-string      $resourceName         Human-readable name for a consent screen
     */
    public function __construct(
        string $resource,
        array $authorizationServers,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $scopesSupported = [],
        ?string $resourceName = null,
    ) {
        $this->document = new ProtectedResourceMetadata(
            new ResourceIdentifier($resource),
            $authorizationServers,
            [] === $scopesSupported ? null : new ScopeSet($scopesSupported),
            [self::BEARER_METHOD_HEADER],
            $resourceName,
        );
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'GET') {
            return $this->responseFactory->createResponse(HttpStatus::MethodNotAllowed->value)->withHeader('Allow', 'GET');
        }

        return $this->responseFactory->createResponse(HttpStatus::Ok->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(
                json_encode($this->document->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ))
        ;
    }
}
