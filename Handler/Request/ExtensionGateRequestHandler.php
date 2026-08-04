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
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Rejects an extension-owned request whose per-request client capabilities
 * did not declare the extension.
 *
 * @internal
 *
 * @implements RequestHandlerInterface<non-empty-string, Result, ServerContext>
 */
final readonly class ExtensionGateRequestHandler implements RequestHandlerInterface
{
    /**
     * @param non-empty-string                                                 $identifier
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     */
    public function __construct(private string $identifier, private RequestHandlerInterface $handler)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result
    {
        \assert($context instanceof ServerContext);
        $declared = $context->meta->clientCapabilities->extensions;

        if (null === $declared || ! \array_key_exists($this->identifier, $declared)) {
            throw new MissingRequiredClientCapabilityException(
                new ClientCapabilities(extensions: [$this->identifier => []]),
                $context->requestId,
            );
        }

        return $this->handler->handle($request, $context);
    }
}
