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

use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Answers whether a request's per-request client capabilities declared an
 * extension, and builds the refusal for one that did not.
 *
 * @internal
 */
final class ExtensionDeclarationGate
{
    /**
     * @param non-empty-string $identifier
     */
    public static function declares(ServerContext $context, string $identifier): bool
    {
        $declared = $context->meta->clientCapabilities->extensions;

        return null !== $declared && \array_key_exists($identifier, $declared);
    }

    /**
     * @param non-empty-string $identifier
     */
    public static function refuse(ServerContext $context, string $identifier): MissingRequiredClientCapabilityException
    {
        return new MissingRequiredClientCapabilityException(
            new ClientCapabilities(extensions: [$identifier => []]),
            $context->requestId,
        );
    }
}
