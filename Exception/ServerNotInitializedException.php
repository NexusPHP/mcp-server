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
 * Thrown when an inbound request other than `initialize` or `ping` arrives
 * before the client has completed the `initialize` handshake.
 */
final class ServerNotInitializedException extends AbstractJsonRpcProtocolException
{
    public function __construct(string $method, ?RequestId $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('Cannot handle "%s" before the client has sent "notifications/initialized".', $method),
            $requestId,
            $previous,
        );
    }

    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidRequest;
    }
}
