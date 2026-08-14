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

use Amp\CancelledException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Subscription\SubscriptionStoreInterface;

/**
 * Serves `subscriptions/listen`, holding the request open for the stream's lifetime and answering only
 * when the server tears the subscription down.
 *
 * @implements RequestHandlerInterface<'subscriptions/listen', SubscriptionsListenResult, ServerContext>
 *
 * @internal
 */
final readonly class SubscriptionsListenRequestHandler implements RequestHandlerInterface
{
    /**
     * @param null|SubscriptionFilter $deliverable The `listChanged` types a change-reporting store backs, or `null` for no narrowing
     */
    public function __construct(
        private SubscriptionStoreInterface $store,
        private ?SubscriptionFilter $deliverable = null,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): SubscriptionsListenResult
    {
        \assert($request instanceof SubscriptionsListenRequest);
        \assert($context instanceof ServerContext);

        $subscriptionId = $context->receiveContext->peerRequestId ?? $context->requestId;
        $entry = $this->store->open($subscriptionId, $this->narrow($request->params->notifications), $context->sender);

        try {
            $entry->closed->getFuture()->await($context->cancellation);
        } catch (CancelledException) {
            // The client abandoned the stream, so the dispatcher drops the response, which is the spec's abrupt close.
        }

        $this->store->discard($entry);

        return new SubscriptionsListenResult(new SubscriptionsListenResultMetaObject(subscriptionId: $subscriptionId));
    }

    /**
     * Drops the `listChanged` types no registered store can produce, so the acknowledgement promises only
     * what `server/discover` advertises.
     */
    private function narrow(SubscriptionFilter $requested): SubscriptionFilter
    {
        if (null === $this->deliverable) {
            return $requested;
        }

        return new SubscriptionFilter(
            toolsListChanged: true === $this->deliverable->toolsListChanged ? $requested->toolsListChanged : null,
            promptsListChanged: true === $this->deliverable->promptsListChanged ? $requested->promptsListChanged : null,
            resourcesListChanged: true === $this->deliverable->resourcesListChanged ? $requested->resourcesListChanged : null,
            resourceSubscriptions: $requested->resourceSubscriptions,
        );
    }
}
