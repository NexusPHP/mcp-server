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

namespace Nexus\Mcp\Server\Handler\Request;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `server/discover` request, advertising the server's supported
 * protocol versions, capabilities, and full identity.
 *
 * @implements RequestHandlerInterface<'server/discover', DiscoverResult, ServerContext>
 */
final readonly class DiscoverRequestHandler implements RequestHandlerInterface
{
    /**
     * @param null|non-empty-string $instructions
     */
    public function __construct(
        private ServerCapabilities $capabilities,
        private ?string $instructions = null,
        private int $ttlMs = 0,
        private CacheScope $cacheScope = CacheScope::Private,
        private ResultMetaObject $meta = new GenericResultMetaObject(),
        private ?Implementation $serverInfo = null,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): DiscoverResult
    {
        return new DiscoverResult(
            supportedVersions: ProtocolVersion::SUPPORTED_VERSIONS,
            capabilities: $this->capabilities,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            instructions: $this->instructions,
            meta: null === $this->serverInfo
                ? $this->meta
                : new GenericResultMetaObject(serverInfo: $this->serverInfo, extras: $this->meta->extras),
        );
    }
}
