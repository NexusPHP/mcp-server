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
 * Serves this MCP server's Protected Resource Metadata document at `/.well-known/oauth-protected-resource{/path}`
 * and its root form, answering `404` on any other path.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728#section-3
 */
final readonly class ProtectedResourceMetadataHandler implements RequestHandlerInterface
{
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
     * @param list<non-empty-string> $scopesSupported
     * @param null|non-empty-string  $resourceName
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
            ['header'],
            $resourceName,
        );

        $path = rtrim((string) parse_url($identifier->value, \PHP_URL_PATH), '/');
        $this->paths = '' === $path
            ? ['/.well-known/oauth-protected-resource']
            : ['/.well-known/oauth-protected-resource'.$path, '/.well-known/oauth-protected-resource'];
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The document describes one MCP server, so it belongs only at the well-known paths RFC 9728 derives from that server's URL.
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
