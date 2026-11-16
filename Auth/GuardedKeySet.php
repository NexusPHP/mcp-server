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

namespace Nexus\Mcp\Server\Auth;

use Firebase\JWT\Key;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Server\Exception\AccessTokenValidationUnavailableException;

/**
 * Read-only key set reporting a failed key id lookup as `AccessTokenValidationUnavailableException`.
 *
 * @internal
 *
 * @implements \ArrayAccess<string, Key>
 */
final readonly class GuardedKeySet implements \ArrayAccess
{
    /**
     * @param \ArrayAccess<string, Key> $keys
     */
    public function __construct(private \ArrayAccess $keys)
    {
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        try {
            return isset($this->keys[$offset]);
        } catch (\Exception $e) {
            throw new AccessTokenValidationUnavailableException('The JWKS signing keys could not be fetched.', previous: $e);
        }
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->keys[$offset];
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException('The JWKS key set is read-only.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('The JWKS key set is read-only.');
    }
}
