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
 * and name the same URL in `BearerAuthenticationMiddleware`'s challenges. Any other path is answered `404`.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728#section-3
 */
final readonly class ProtectedResourceMetadataHandler implements RequestHandlerInterface
{
    /**
     * MCP requires the token in the `Authorization` header and forbids it in the query string.
     */
    private const string BEARER_METHOD_HEADER = 'header';

    private const string WELL_KNOWN_PATH = '/.well-known/oauth-protected-resource';

    private ProtectedResourceMetadata $document;

    /**
     * The request paths this document belongs at, path-scoped before root.
     *
     * @var list<string>
     */
    private array $paths;

    /**
     * @param string                 $resource             Canonical URI of this MCP server
     * @param list<non-empty-string> $authorizationServers Issuers that mint tokens for it, at least one
     * @param list<non-empty-string> $scopesSupported      Scopes basic use of this server calls for
     * @param null|non-empty-string  $resourceName         Human-readable name for a consent screen
     */
    public function __construct(
        string $resource,
        array $authorizationServers,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $scopesSupported = [],
        ?string $resourceName = null,
    ) {
        $identifier = new ResourceIdentifier($resource);
        $this->document = new ProtectedResourceMetadata(
            $identifier,
            $authorizationServers,
            [] === $scopesSupported ? null : new ScopeSet($scopesSupported),
            [self::BEARER_METHOD_HEADER],
            $resourceName,
        );

        $path = rtrim((string) parse_url($identifier->value, \PHP_URL_PATH), '/');
        $this->paths = '' === $path
            ? [self::WELL_KNOWN_PATH]
            : [self::WELL_KNOWN_PATH.$path, self::WELL_KNOWN_PATH];
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The document describes one MCP server, so it belongs only at the well-known paths RFC 9728 derives
        // from that server's URL, however many routes the handler is mounted on.
        if (! \in_array($request->getUri()->getPath(), $this->paths, true)) {
            return $this->responseFactory->createResponse(HttpStatus::NotFound->value);
        }

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
