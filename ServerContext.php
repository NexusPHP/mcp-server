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

namespace Nexus\Mcp\Server;

use Amp\Cancellation;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Core\Transport\ReceiveContext;

/**
 * Context passed to server-side request handlers.
 */
final readonly class ServerContext extends AbstractContext
{
    /**
     * @param null|array<string, InputResponse> $inputResponses The client's answers to a prior `InputRequiredResult`
     * @param null|string                       $requestState   The continuation token that result carried, echoed back unchanged
     */
    public function __construct(
        RequestId $requestId,
        Cancellation $cancellation,
        public RequestMetaObject $meta,
        SenderInterface $sender,
        public ReceiveContext $receiveContext = new ReceiveContext(),
        public ?array $inputResponses = null,
        public ?string $requestState = null,
    ) {
        parent::__construct($requestId, $cancellation, $meta->progressToken, $sender);
    }
}
