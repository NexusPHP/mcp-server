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
 * Tracks the client handshake lifecycle and decides which inbound request
 * methods may run before it completes.
 */
final class InitializationGate
{
    private const array ALWAYS_ALLOWED_REQUESTS = ['initialize', 'ping'];

    private InitializationState $state = InitializationState::AwaitingInitialize;

    public function isInitialized(): bool
    {
        return InitializationState::Initialized === $this->state;
    }

    /**
     * @param non-empty-string $requestMethod
     */
    public function allowsRequest(string $requestMethod): bool
    {
        return $this->isInitialized() || \in_array($requestMethod, self::ALWAYS_ALLOWED_REQUESTS, true);
    }

    /**
     * Transitions `AwaitingInitialize` -> `InitializeInFlight`. Returns `true`
     * if the transition fired; `false` if the gate was already past that state.
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
     * Transitions `InitializeInFlight` -> `Initialized`. Returns `true` if the
     * transition fired; `false` if the gate was not awaiting an `initialized`
     * notification (either still awaiting the `initialize` request or already
     * past the handshake).
     */
    public function markInitialized(): bool
    {
        if (InitializationState::InitializeInFlight !== $this->state) {
            return false;
        }

        $this->state = InitializationState::Initialized;

        return true;
    }
}
