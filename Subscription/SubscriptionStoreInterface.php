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

use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Server\Exception\SubscriptionLimitReachedException;

/**
 * Holds the open `subscriptions/listen` streams and fans server-side events out to the ones that asked for them.
 */
interface SubscriptionStoreInterface
{
    /**
     * Opens a stream, acknowledging it before it becomes visible to any emit.
     *
     * @param RequestId $subscriptionId Id every message on the stream carries, as the client sent it
     *
     * @throws SubscriptionLimitReachedException
     */
    public function open(RequestId $subscriptionId, SubscriptionFilter $requested, SenderInterface $sender): SubscriptionEntry;

    /**
     * Narrows `$requested` to the notification types this store delivers, omitting rather than
     * falsifying the ones it does not honour.
     */
    public function honour(SubscriptionFilter $requested): SubscriptionFilter;

    /**
     * Tears `$entry` down, naming the ending `subscriptions/listen` to the client and releasing the
     * handler, and does nothing for a stream already gone.
     */
    public function close(SubscriptionEntry $entry): void;

    /**
     * Deregisters `$entry` without announcing anything, for a stream the client already abandoned.
     */
    public function discard(SubscriptionEntry $entry): void;

    /**
     * Closes every open stream so the server can drain, settling any opened afterwards at once.
     */
    public function closeAll(): void;

    public function emitToolListChanged(): void;

    public function emitPromptListChanged(): void;

    public function emitResourceListChanged(): void;

    /**
     * Announces that the contents behind `$uri` changed, to the streams subscribed to that URI.
     */
    public function emitResourceUpdated(string $uri): void;
}
