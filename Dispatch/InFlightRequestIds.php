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

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Tracks the set of inbound JSON-RPC request ids whose handler coroutines are still running.
 *
 * @internal
 */
final class InFlightRequestIds implements \Countable
{
    /**
     * @var array<int, true>
     */
    private array $intIds = [];

    /**
     * @var array<non-empty-string, true>
     */
    private array $stringIds = [];

    public function tryClaim(RequestId $id): bool
    {
        if (\is_int($id->id)) {
            if (\array_key_exists($id->id, $this->intIds)) {
                return false;
            }

            $this->intIds[$id->id] = true;

            return true;
        }

        if (\array_key_exists($id->id, $this->stringIds)) {
            return false;
        }

        $this->stringIds[$id->id] = true;

        return true;
    }

    public function release(RequestId $id): void
    {
        if (\is_int($id->id)) {
            unset($this->intIds[$id->id]);
        } else {
            unset($this->stringIds[$id->id]);
        }
    }

    public function contains(RequestId $id): bool
    {
        return \is_int($id->id)
            ? \array_key_exists($id->id, $this->intIds)
            : \array_key_exists($id->id, $this->stringIds);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->intIds) + \count($this->stringIds);
    }
}
