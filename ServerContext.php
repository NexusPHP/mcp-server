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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;

/**
 * Context passed to server-side request handlers.
 */
final readonly class ServerContext extends AbstractContext
{
    public function __construct(
        RequestId $requestId,
        Cancellation $cancellation,
        public RequestMetaObject $meta,
        SenderInterface $sender,
    ) {
        parent::__construct($requestId, $cancellation, $meta->progressToken, $sender);
    }
}
