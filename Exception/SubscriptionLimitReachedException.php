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
 * Thrown when a `subscriptions/listen` asks for more than one of the store's budgets allows.
 */
final class SubscriptionLimitReachedException extends AbstractJsonRpcProtocolException
{
    /**
     * @param bool $perPeer   Whether the per-peer budget refused the stream, rather than the server-wide one
     * @param bool $perStream Whether the stream named more resource URIs than one may watch
     */
    public function __construct(
        public readonly int $limit,
        ?RequestId $requestId = null,
        ?\Throwable $previous = null,
        bool $perPeer = false,
        bool $perStream = false,
    ) {
        parent::__construct(
            $requestId,
            $perStream
                ? \sprintf('Subscription limit reached: this server watches at most %d resource URIs per stream.', $limit)
                : \sprintf(
                    'Subscription limit reached: this server holds at most %d open streams%s.',
                    $limit,
                    $perPeer ? ' per client' : '',
                ),
            $previous,
            errorData: ['limit' => $limit],
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InternalError;
    }
}
