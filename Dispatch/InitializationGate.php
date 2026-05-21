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

use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;

/**
 * Tracks the client handshake lifecycle and decides which inbound request
 * methods may run before it completes.
 */
final class InitializationGate
{
    private InitializationState $state = InitializationState::AwaitingInitialize;
    private bool $pendingInitializedNotification = false;

    public function isInitialized(): bool
    {
        return InitializationState::Initialized === $this->state;
    }

    /**
     * @param non-empty-string $requestMethod
     */
    public function allowsRequest(string $requestMethod): bool
    {
        if (InitializeRequest::method() === $requestMethod) {
            return InitializationState::AwaitingInitialize === $this->state;
        }

        return $this->isInitialized() || PingRequest::method() === $requestMethod;
    }

    /**
     * Transitions `AwaitingInitialize` -> `InitializeInFlight`. Returns `true` if the transition fired.
     */
    public function markInitializeInFlight(): bool
    {
        if (InitializationState::AwaitingInitialize !== $this->state) {
            return false;
        }

        $this->state = InitializationState::InitializeInFlight;

        return true;
    }

    /**
     * From `InitializeInFlight`: if a `notifications/initialized` was buffered while the handler was
     * still running, transition straight to `Initialized` (consuming the pending flag). Otherwise
     * transition to `InitializeCompleted` to wait for the notification. Returns `true` if either
     * transition fired.
     */
    public function markInitializeCompleted(): bool
    {
        if (InitializationState::InitializeInFlight !== $this->state) {
            return false;
        }

        $this->state = $this->pendingInitializedNotification
            ? InitializationState::Initialized
            : InitializationState::InitializeCompleted;

        return true;
    }

    /**
     * From `InitializeCompleted`: transition to `Initialized`. From `InitializeInFlight`: buffer the
     * notification (the handler has not finished yet). `markInitializeCompleted` will consume the
     * buffered flag on success. Returns `true` if the notification was accepted (flipped the gate or
     * got buffered). Returns `false` when there is no in-flight handshake to apply the notification
     * to, or when a notification was already buffered (duplicate).
     */
    public function markInitialized(): bool
    {
        if (InitializationState::InitializeCompleted === $this->state) {
            $this->state = InitializationState::Initialized;

            return true;
        }

        if (InitializationState::InitializeInFlight === $this->state && ! $this->pendingInitializedNotification) {
            $this->pendingInitializedNotification = true;

            return true;
        }

        return false;
    }

    /**
     * Reverts `InitializeInFlight` -> `AwaitingInitialize`. Also clears any buffered notification so a
     * retry handshake starts fresh. Returns `true` if the transition fired.
     */
    public function revertInitializeInFlight(): bool
    {
        if (InitializationState::InitializeInFlight !== $this->state) {
            return false;
        }

        $this->state = InitializationState::AwaitingInitialize;
        $this->pendingInitializedNotification = false;

        return true;
    }
}
