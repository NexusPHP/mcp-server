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
use Nexus\Clock\Clock;
use Nexus\Clock\SystemClock;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Core\Transport\ReceiveContext;

/**
 * Mints and checks the `requestState` carried by an `InputRequiredResult` across a round trip. It signs
 * without encrypting, so a state may hold a continuation marker and never a secret.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr
 */
final readonly class RequestStateSigner
{
    public const int DEFAULT_TTL_SECONDS = 600;

    /**
     * Separates the payload, the expiry and the signature, on the last occurrences since neither the
     * expiry nor the hexadecimal signature contains it.
     */
    private const string SEPARATOR = '.';

    private const int SECRET_BYTES = 32;

    /**
     * @var non-empty-list<non-empty-string>
     */
    private array $secrets;

    /**
     * @param non-empty-list<non-empty-string>|non-empty-string                       $secret     The signing key, or a key ring whose first key signs and whose every key verifies
     * @param int<1, max>                                                             $ttlSeconds How long a minted state verifies
     * @param string                                                                  $binding    The binding applied when `sign()` and `verify()` are given none
     * @param null|\Closure(JsonRpcRequest<non-empty-string>, ReceiveContext): string $policy     Derives the binding for a request in `bindFor()`, the authenticated subject and client, method and target when null
     */
    public function __construct(
        array|string $secret,
        private string $algorithm = 'sha256',
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private Clock $clock = new SystemClock(),
        private string $binding = '',
        private ?\Closure $policy = null,
    ) {
        if (\is_string($secret)) {
            Assert::that($secret)->isNonEmptyString('The request-state signing secret must be a non-empty string.');
            $secret = [$secret];
        }

        Assert::that($secret)
            ->isNonEmptyList('The request-state signing key ring must be a non-empty list, {type} given.')
            ->values()->isNonEmptyString('Each request-state signing key must be a non-empty string, {type} given.')
        ;
        $this->secrets = $secret;

        Assert::that($algorithm)->isOneOf(
            hash_hmac_algos(),
            \sprintf('The request-state signing algorithm "%s" is not available.', $algorithm),
        );
        Assert::that($ttlSeconds)->isPositiveInt('The request-state TTL must be a positive number of seconds, {value} given.');
    }

    /**
     * A signing key drawn from the system's random source, for a server that mints no state
     * beyond the lifetime of its own process.
     *
     * @param int<1, max> $ttlSeconds
     */
    public static function generate(int $ttlSeconds = self::DEFAULT_TTL_SECONDS): self
    {
        return new self(bin2hex(random_bytes(self::SECRET_BYTES)), ttlSeconds: $ttlSeconds);
    }

    /**
     * The binding for a request under the default policy: the authenticated subject and client, the method,
     * the tool name, prompt name or resource URI it targets, and a digest of its arguments.
     *
     * @param JsonRpcRequest<non-empty-string> $request
     */
    public static function deriveBinding(JsonRpcRequest $request, ReceiveContext $receiveContext): string
    {
        $params = $request->params;
        [$target, $arguments] = match (true) {
            $params instanceof CallToolRequestParams, $params instanceof GetPromptRequestParams => [$params->name, $params->arguments],
            $params instanceof ResourceRequestParams => [$params->uri, null],
            default => ['', null],
        };

        return json_encode([
            $receiveContext->authInfo->subject ?? null,
            $receiveContext->authInfo->clientId ?? null,
            $request::getMethod(),
            $target,
            null === $arguments ? '' : hash('sha256', json_encode(self::sortKeys($arguments), \JSON_THROW_ON_ERROR)),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Mints a state, bound to `$binding` when one is given and to this signer's own binding otherwise.
     *
     * Pass whatever identifies the caller entitled to resume: an unbound state is replayable by any caller.
     */
    public function sign(string $payload, string $binding = ''): string
    {
        return $this->seal(
            $payload,
            $this->clock->now()->getTimestamp() + $this->ttlSeconds,
            '' === $binding ? $this->binding : $binding,
            $this->secrets[0],
        );
    }

    /**
     * The payload carried by a state, or null when it has expired or its signature does not hold, meaning
     * this server did not mint it or minted it for a different `$binding`.
     */
    public function verify(string $state, string $binding = ''): ?string
    {
        $signatureAt = strrpos($state, self::SEPARATOR);

        if (false === $signatureAt) {
            return null;
        }

        $body = substr($state, 0, $signatureAt);
        $expiryAt = strrpos($body, self::SEPARATOR);

        if (false === $expiryAt) {
            return null;
        }

        $payload = substr($body, 0, $expiryAt);
        $expires = (int) substr($body, $expiryAt + 1);

        $binding = '' === $binding ? $this->binding : $binding;

        foreach ($this->secrets as $secret) {
            if (hash_equals($this->seal($payload, $expires, $binding, $secret), $state)) {
                return $expires < $this->clock->now()->getTimestamp() ? null : $payload;
            }
        }

        return null;
    }

    /**
     * A signer whose `sign()` and `verify()` default to `$binding`.
     */
    public function bindTo(string $binding): self
    {
        return new self($this->secrets, $this->algorithm, $this->ttlSeconds, $this->clock, $binding, $this->policy);
    }

    /**
     * A signer deriving the binding for a request through `$policy` instead of the default one.
     *
     * @param \Closure(JsonRpcRequest<non-empty-string>, ReceiveContext): string $policy
     */
    public function bindBy(\Closure $policy): self
    {
        return new self($this->secrets, $this->algorithm, $this->ttlSeconds, $this->clock, $this->binding, $policy);
    }

    /**
     * A signer bound to `$request` under the policy, so a state it mints resumes that request alone.
     *
     * @param JsonRpcRequest<non-empty-string> $request
     */
    public function bindFor(JsonRpcRequest $request, ReceiveContext $receiveContext): self
    {
        return $this->bindTo(null === $this->policy
            ? self::deriveBinding($request, $receiveContext)
            : ($this->policy)($request, $receiveContext));
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $arguments): array
    {
        ksort($arguments);

        foreach ($arguments as $key => $value) {
            if (\is_array($value)) {
                $arguments[$key] = self::sortKeys($value);
            }
        }

        return $arguments;
    }

    private function seal(string $payload, int $expires, string $binding, string $secret): string
    {
        $body = $payload.self::SEPARATOR.$expires;

        return $body.self::SEPARATOR.hash_hmac($this->algorithm, \strlen($binding).self::SEPARATOR.$binding.$body, $secret);
    }
}
