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
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when an inbound request reuses an id whose handler coroutine is still
 * running on the same session.
 */
final class DuplicateInboundRequestIdException extends AbstractJsonRpcProtocolException
{
    public function __construct(RequestId $requestId, ?\Throwable $previous = null)
    {
        parent::__construct(
            $requestId,
            'Inbound request id is already pending on this session.',
            $previous,
        );
    }

    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidRequest;
    }
}
