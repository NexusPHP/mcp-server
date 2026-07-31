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
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ResourceUpdatedNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Server\Exception\SubscriptionLimitReachedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

/**
 * In-memory implementation of `SubscriptionStoreInterface`.
 */
final class SubscriptionStore implements SubscriptionStoreInterface
{
    /**
     * How many streams one store holds open before it starts refusing new ones.
     */
    public const int DEFAULT_MAX_SUBSCRIPTIONS = 1024;

    private const string TOOLS = 'tools';
    private const string PROMPTS = 'prompts';
    private const string RESOURCES = 'resources';

    /**
     * Keyed by object identity, not by any request id. Request ids are unique per connection, and a
     * sessionless endpoint serves many connections through one store.
     *
     * @var array<int, SubscriptionEntry>
     */
    private array $entries = [];

    private bool $drained = false;

    /**
     * List-change kinds, and resource URIs, awaiting the end of the current event-loop tick.
     *
     * @var array<non-empty-string, true>
     */
    private array $pendingListChanges = [];

    /**
     * @var array<non-empty-string, true>
     */
    private array $pendingResourceUpdates = [];

    public function __construct(
        private readonly bool $toolsListChanged = false,
        private readonly bool $promptsListChanged = false,
        private readonly bool $resourcesListChanged = false,
        private readonly bool $resourceSubscriptions = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxSubscriptions = self::DEFAULT_MAX_SUBSCRIPTIONS,
    ) {
        Assert::that($maxSubscriptions)
            ->isPositiveInt('The maximum open subscriptions must be a positive integer, {value} given.')
        ;
    }

    #[\Override]
    public function open(RequestId $subscriptionId, SubscriptionFilter $requested, SenderInterface $sender): SubscriptionEntry
    {
        if ($this->maxSubscriptions <= \count($this->entries)) {
            // Refused before the acknowledgement, so the client never sees a stream it does not have.
            throw new SubscriptionLimitReachedException($this->maxSubscriptions, $subscriptionId);
        }

        $honoured = $this->honour($requested);

        /** @var DeferredFuture<null> $closed */
        $closed = new DeferredFuture();
        $entry = new SubscriptionEntry($subscriptionId, $honoured, $sender, $closed);

        // The spec makes the acknowledgement the first message on the stream, so it goes out before the
        // entry is visible to any emit.
        $sender->sendNotification(new SubscriptionsAcknowledgedNotification(
            params: new SubscriptionsAcknowledgedNotificationParams(
                notifications: $honoured,
                meta: new NotificationMetaObject(subscriptionId: $subscriptionId),
            ),
        ));

        if ($this->drained) {
            // The server is shutting down. Settle the stream at once so its handler cannot outlive the drain.
            $closed->complete();

            return $entry;
        }

        $this->entries[spl_object_id($entry)] = $entry;

        return $entry;
    }

    #[\Override]
    public function close(SubscriptionEntry $entry): void
    {
        if (! \array_key_exists(spl_object_id($entry), $this->entries)) {
            return;
        }

        $this->discard($entry);

        // The spec has the server name the `subscriptions/listen` request it is tearing down.
        $this->pushTo($entry, new CancelledNotification(
            params: new CancelledNotificationParams(requestId: $entry->subscriptionId),
        ));

        $entry->closed->complete();
    }

    #[\Override]
    public function discard(SubscriptionEntry $entry): void
    {
        unset($this->entries[spl_object_id($entry)]);
    }

    #[\Override]
    public function closeAll(): void
    {
        $this->drained = true;

        foreach ($this->entries as $entry) {
            $this->close($entry);
        }
    }

    #[\Override]
    public function emitToolListChanged(): void
    {
        $this->coalesceListChange(self::TOOLS);
    }

    #[\Override]
    public function emitPromptListChanged(): void
    {
        $this->coalesceListChange(self::PROMPTS);
    }

    #[\Override]
    public function emitResourceListChanged(): void
    {
        $this->coalesceListChange(self::RESOURCES);
    }

    #[\Override]
    public function emitResourceUpdated(string $uri): void
    {
        Assert::that($uri)->isNonEmptyString('An updated resource URI must be a non-empty string.');

        $this->pendingResourceUpdates[$uri] = true;
        $this->scheduleFlush();
    }

    #[\Override]
    public function honour(SubscriptionFilter $requested): SubscriptionFilter
    {
        return new SubscriptionFilter(
            toolsListChanged: $this->toolsListChanged && true === $requested->toolsListChanged ? true : null,
            promptsListChanged: $this->promptsListChanged && true === $requested->promptsListChanged ? true : null,
            resourcesListChanged: $this->resourcesListChanged && true === $requested->resourcesListChanged ? true : null,
            resourceSubscriptions: $this->resourceSubscriptions ? $requested->resourceSubscriptions : null,
        );
    }

    /**
     * @param self::PROMPTS|self::RESOURCES|self::TOOLS $kind
     */
    private function coalesceListChange(string $kind): void
    {
        $this->pendingListChanges[$kind] = true;
        $this->scheduleFlush();
    }

    /**
     * Holds announcements until the end of the tick, so a burst of mutations reaches each stream once.
     */
    private function scheduleFlush(): void
    {
        EventLoop::defer(function (): void {
            $kinds = $this->pendingListChanges;
            $uris = $this->pendingResourceUpdates;
            $this->pendingListChanges = [];
            $this->pendingResourceUpdates = [];

            foreach ([self::TOOLS, self::PROMPTS, self::RESOURCES] as $kind) {
                if (\array_key_exists($kind, $kinds)) {
                    $this->broadcastListChange($kind);
                }
            }

            foreach (array_keys($uris) as $uri) {
                // An all-digit URI is a legal string but an int array key, so it comes back coerced.
                $this->broadcastResourceUpdate((string) $uri);
            }
        });
    }

    /**
     * @param non-empty-string $kind
     */
    private function broadcastListChange(string $kind): void
    {
        foreach ($this->entries as $entry) {
            $honoured = $entry->honoured;
            $wanted = match ($kind) {
                self::TOOLS => true === $honoured->toolsListChanged,
                self::PROMPTS => true === $honoured->promptsListChanged,
                default => true === $honoured->resourcesListChanged,
            };

            if (! $wanted) {
                continue;
            }

            $params = new EmptyNotificationParams(new NotificationMetaObject(subscriptionId: $entry->subscriptionId));
            $this->pushTo($entry, match ($kind) {
                self::TOOLS => new ToolListChangedNotification(params: $params),
                self::PROMPTS => new PromptListChangedNotification(params: $params),
                default => new ResourceListChangedNotification(params: $params),
            });
        }
    }

    /**
     * @param non-empty-string $uri
     */
    private function broadcastResourceUpdate(string $uri): void
    {
        foreach ($this->entries as $entry) {
            if (! \in_array($uri, $entry->honoured->resourceSubscriptions ?? [], true)) {
                continue;
            }

            $this->pushTo($entry, new ResourceUpdatedNotification(
                params: new ResourceUpdatedNotificationParams(
                    uri: $uri,
                    meta: new NotificationMetaObject(subscriptionId: $entry->subscriptionId),
                ),
            ));
        }
    }

    /**
     * Sends one notification to one stream. A send that fails must not cost the streams behind it, and a
     * stream torn down mid-broadcast must hear nothing further.
     *
     * @param JsonRpcNotification<non-empty-string> $notification
     */
    private function pushTo(SubscriptionEntry $entry, JsonRpcNotification $notification): void
    {
        if ($entry->closed->isComplete()) {
            return;
        }

        try {
            $entry->sender->sendNotification($notification);
        } catch (\Throwable $e) {
            $this->logger->debug('Dropping a subscription notification its stream could not take.', [
                'method' => $notification::getMethod(),
                'exception' => $e,
            ]);
        }
    }
}
