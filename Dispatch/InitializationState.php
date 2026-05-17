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
     * The `initialize` handler returned a result. Awaiting `notifications/initialized`.
     */
    case InitializeInFlight;

    /**
     * Client sent `notifications/initialized`. The session may proceed.
     */
    case Initialized;
}
