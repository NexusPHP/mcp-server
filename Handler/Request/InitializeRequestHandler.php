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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `initialize` request, returning the server's protocol version,
 * capabilities, and identification info.
 *
 * @implements RequestHandlerInterface<InitializeRequest, InitializeResult, ServerContext>
 */
final readonly class InitializeRequestHandler implements RequestHandlerInterface
{
    /**
     * @param null|non-empty-string $instructions
     */
    public function __construct(
        private Implementation $serverInfo,
        private ServerCapabilities $capabilities,
        private ?string $instructions = null,
        private MetaObject $meta = new MetaObject(),
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): InitializeResult
    {
        return new InitializeResult(
            ProtocolVersion::latest(),
            $this->capabilities,
            $this->serverInfo,
            $this->instructions,
            $this->meta,
        );
    }
}
