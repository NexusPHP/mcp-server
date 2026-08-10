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
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when a `prompts/get` references a prompt that is not registered with the store.
 */
final class PromptNotFoundException extends AbstractJsonRpcProtocolException
{
    public function __construct(
        public readonly string $name,
        ?RequestId $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $requestId,
            \sprintf('No prompt registered under name "%s".', SafeDisplay::sanitise($name)),
            $previous,
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidParams;
    }
}
