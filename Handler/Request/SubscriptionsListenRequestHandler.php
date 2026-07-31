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
    public function __construct(private SubscriptionStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): SubscriptionsListenResult
    {
        \assert($request instanceof SubscriptionsListenRequest);
        \assert($context instanceof ServerContext);

        // A request-scoped transport dispatches under an id of its own, so the id the client will match
        // this stream against is the one it sent, not the one the handler was dispatched with.
        $subscriptionId = $context->receiveContext->peerRequestId ?? $context->requestId;
        $entry = $this->store->open($subscriptionId, $request->params->notifications, $context->sender);

        try {
            $entry->closed->getFuture()->await($context->cancellation);
        } catch (CancelledException) {
            // The client abandoned the stream. The dispatcher drops the response, which is what the spec
            // means by an abrupt close carrying none.
        }

        $this->store->discard($entry);

        return new SubscriptionsListenResult(new SubscriptionsListenResultMetaObject(subscriptionId: $subscriptionId));
    }
}
