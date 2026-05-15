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

namespace Nexus\Mcp\Server\Dispatch;

use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;

/**
 * `SenderInterface` implementation scoped to a single inbound request. Tags
 * outbound notifications with `relatedRequestId` so the transport can route
 * them back to the originating session (used by streamable HTTP).
 */
final readonly class RequestBoundSender implements SenderInterface
{
    private SendContext $outboundContext;

    public function __construct(private TransportInterface $transport, private RequestId $requestId)
    {
        $this->outboundContext = new SendContext(relatedRequestId: $this->requestId);
    }

    #[\Override]
    public function sendNotification(JsonRpcNotification $notification): void
    {
        $this->transport->send($notification, $this->outboundContext);
    }

    /**
     * @todo Implement when sampling (`sampling/createMessage`) and elicitation
     *       (`elicitation/create`) request handlers are introduced. The transport
     *       needs an outbound-request correlation table keyed by `RequestId` so
     *       inbound `JsonRpcResultResponse` and `JsonRpcErrorResponse` envelopes
     *       can be routed back to the awaiting `Future`.
     *
     * @return never
     */
    #[\Override]
    public function sendRequest(JsonRpcRequest $request): JsonRpcResultResponse
    {
        throw new \BadMethodCallException(
            'Outbound server-to-client requests are not implemented yet.',
        );
    }
}
