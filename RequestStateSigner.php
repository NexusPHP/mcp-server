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
 * Mints and checks the `requestState` an `InputRequiredResult` carries across a round trip.
 *
 * The payload travels in the clear and is signed, not encrypted, so a state may hold a
 * continuation marker but never a secret.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr
 */
final readonly class RequestStateSigner
{
    /**
     * Separates the payload from its signature. Hexadecimal signatures never contain it, so the
     * last occurrence always splits the two however many the payload holds.
     */
    private const string SEPARATOR = '.';

    /**
     * Entropy behind a generated signing key, in bytes.
     */
    private const int SECRET_BYTES = 32;

    /**
     * @param string $secret Signing key, held only by the server that mints the state
     */
    public function __construct(private string $secret, private string $algorithm = 'sha256')
    {
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

    public function sign(string $payload): string
    {
        return $payload.self::SEPARATOR.hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * The payload a state carries, or null when its signature does not hold. A handler that
     * receives null has been handed a state this server did not mint.
     */
    public function verify(string $state): ?string
    {
        $split = strrpos($state, self::SEPARATOR);

        if (false === $split) {
            return null;
        }

        $payload = substr($state, 0, $split);

        return hash_equals($this->sign($payload), $state) ? $payload : null;
    }
}
