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

/**
 * Lifecycle states tracked by `InitializationGate`.
 *
 * @internal
 */
enum InitializationState
{
    /**
     * No `initialize` request has been received yet.
     */
    case AwaitingInitialize;

    /**
     * `initialize` request accepted, handler queued (may not have run yet). Blocks a second `initialize`.
     */
    case InitializeInFlight;

    /**
     * `initialize` handler returned successfully. Awaiting `notifications/initialized`.
     */
    case InitializeCompleted;

    /**
     * Client sent `notifications/initialized`. The session may proceed.
     */
    case Initialized;
}
