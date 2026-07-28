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

namespace Nexus\Mcp\Server\Exception;

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when serving a request would rely on a client capability absent from the request's
 * `io.modelcontextprotocol/clientCapabilities`.
 */
final class MissingRequiredClientCapabilityException extends AbstractJsonRpcProtocolException
{
    public function __construct(
        public readonly ClientCapabilities $requiredCapabilities,
        ?RequestId $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $requestId,
            \sprintf(
                'This request requires client capabilities the client did not declare: %s.',
                implode(', ', array_keys($requiredCapabilities->toArray())),
            ),
            $previous,
            errorData: ['requiredCapabilities' => $requiredCapabilities->toArray()],
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::MissingRequiredClientCapability;
    }
}
