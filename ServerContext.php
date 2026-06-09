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
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;

/**
 * Context passed to server-side request handlers.
 */
final readonly class ServerContext extends AbstractContext
{
    public function __construct(
        RequestId $requestId,
        Cancellation $cancellation,
        public RequestMetaObject $meta,
        ?string $sessionId,
        SenderInterface $sender,
        private LoggingLevelGate $gate = new LoggingLevelGate(),
    ) {
        parent::__construct($requestId, $cancellation, $meta->progressToken, $sessionId, $sender);
    }

    public function log(LoggingLevel $level, mixed $data, ?string $logger = null): void
    {
        if (! $this->gate->shouldEmit($level)) {
            return;
        }

        $this->sender->sendNotification(new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(level: $level, data: $data, logger: $logger),
        ));
    }
}
