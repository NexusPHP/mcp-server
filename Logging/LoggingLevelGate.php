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

namespace Nexus\Mcp\Server\Logging;

use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;

/**
 * Holds the minimum `LoggingLevel` for server-emitted log notifications,
 * consulted by `ServerContext::log()`.
 */
final class LoggingLevelGate
{
    public function __construct(public private(set) LoggingLevel $level = LoggingLevel::Info)
    {
    }

    public function shouldEmit(LoggingLevel $messageLevel): bool
    {
        return self::resolveSeverityIndex($messageLevel) <= self::resolveSeverityIndex($this->level);
    }

    /**
     * RFC 5424 numeric severity index (0 = most severe, 7 = least).
     *
     * @return int<0, 7>
     */
    private static function resolveSeverityIndex(LoggingLevel $level): int
    {
        return match ($level) {
            LoggingLevel::Emergency => 0,
            LoggingLevel::Alert => 1,
            LoggingLevel::Critical => 2,
            LoggingLevel::Error => 3,
            LoggingLevel::Warning => 4,
            LoggingLevel::Notice => 5,
            LoggingLevel::Info => 6,
            LoggingLevel::Debug => 7,
        };
    }
}
