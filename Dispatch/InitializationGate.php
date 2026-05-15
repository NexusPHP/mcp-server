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
 * Tracks whether the client has completed the `initialize` handshake and
 * decides which inbound request methods may run before that point.
 */
final class InitializationGate
{
    private const array ALWAYS_ALLOWED_REQUESTS = ['initialize', 'ping'];

    private bool $initialized = false;

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @param non-empty-string $requestMethod
     */
    public function allowsRequest(string $requestMethod): bool
    {
        return $this->initialized || \in_array($requestMethod, self::ALWAYS_ALLOWED_REQUESTS, true);
    }

    public function markInitialized(): void
    {
        $this->initialized = true;
    }
}
