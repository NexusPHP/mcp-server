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
 * Thrown when a second `initialize` request arrives after the handshake has
 * already started or completed for this session.
 */
final class ServerAlreadyInitializedException extends AbstractJsonRpcProtocolException
{
    public function __construct(?RequestId $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            $requestId,
            'Cannot re-initialize: the "initialize" handshake has already started or completed for this session.',
            $previous,
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidRequest;
    }
}
