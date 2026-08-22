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

use Nexus\Assert\Assert;

/**
 * Mints and checks the `requestState` an `InputRequiredResult` carries across a round trip. It signs
 * without encrypting, so a state may hold a continuation marker and never a secret.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr
 */
final readonly class RequestStateSigner
{
    /**
     * Separates the payload from its signature, on the last occurrence since a hexadecimal
     * signature never contains it.
     */
    private const string SEPARATOR = '.';

    private const int SECRET_BYTES = 32;

    public function __construct(
        private string $secret,
        private string $algorithm = 'sha256',
    ) {
        Assert::that($secret)->isNonEmptyString('The request-state signing secret must be a non-empty string.');
        Assert::that($algorithm)->isOneOf(
            hash_hmac_algos(),
            \sprintf('The request-state signing algorithm "%s" is not available.', $algorithm),
        );
    }

    /**
     * A signing key drawn from the system's random source, for a server that mints no state
     * beyond the lifetime of its own process.
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(self::SECRET_BYTES)));
    }

    /**
     * Mints a state, bound to `$binding` when one is given.
     *
     * Pass whatever identifies the caller entitled to resume: an unbound state is replayable by any caller.
     */
    public function sign(string $payload, string $binding = ''): string
    {
        return $payload.self::SEPARATOR.hash_hmac($this->algorithm, $this->bind($binding, $payload), $this->secret);
    }

    /**
     * The payload a state carries, or null when its signature does not hold, meaning this server did
     * not mint it or minted it for a different `$binding`.
     */
    public function verify(string $state, string $binding = ''): ?string
    {
        $split = strrpos($state, self::SEPARATOR);

        if (false === $split) {
            return null;
        }

        $payload = substr($state, 0, $split);

        return hash_equals($this->sign($payload, $binding), $state) ? $payload : null;
    }

    /**
     * Length-prefixes the binding, so no two binding-and-payload pairs share a signing input.
     */
    private function bind(string $binding, string $payload): string
    {
        return \strlen($binding).self::SEPARATOR.$binding.$payload;
    }
}
