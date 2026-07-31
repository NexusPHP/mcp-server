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

namespace Nexus\Mcp\Server\Subscription;

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;

/**
 * One open `subscriptions/listen` stream: the id every message on it carries, the notification types the
 * server agreed to honour, and the pump that reaches the client.
 */
final readonly class SubscriptionEntry
{
    /**
     * @param DeferredFuture<null> $closed Completed when the stream is torn down, releasing the held-open handler
     */
    public function __construct(
        public RequestId $subscriptionId,
        public SubscriptionFilter $honoured,
        public SenderInterface $sender,
        public DeferredFuture $closed,
    ) {
    }
}
