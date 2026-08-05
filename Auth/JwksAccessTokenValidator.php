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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;

/**
 * Validates JWT bearer tokens against a key set, reading the claim spellings the common
 * authorization servers use. Needs the suggested `firebase/php-jwt` package.
 */
final readonly class JwksAccessTokenValidator implements AccessTokenValidatorInterface
{
    /**
     * @param array<string, Key>|\ArrayAccess<string, Key> $keys Keys by `kid`, typically a `Firebase\JWT\CachedKeySet`
     */
    public function __construct(private array|\ArrayAccess $keys)
    {
        SuggestedDependencyGuard::verify(self::class, JWT::class, 'firebase/php-jwt', '^7.0');
    }

    #[\Override]
    public function validate(string $token): ?VerifiedAccessToken
    {
        try {
            $claims = (array) JWT::decode($token, $this->keys);
        } catch (\Exception) {
            // Signature, expiry, and shape failures all mean the same thing to the caller: no grant.
            return null;
        }

        $clientId = $claims['azp'] ?? $claims['client_id'] ?? $claims['cid'] ?? null;
        $subject = $claims['sub'] ?? null;
        $expiresAt = $claims['exp'] ?? null;

        return new VerifiedAccessToken(
            audience: self::readAudience($claims),
            scopes: self::readScopes($claims),
            subject: \is_string($subject) ? $subject : null,
            clientId: \is_string($clientId) ? $clientId : null,
            expiresAt: \is_int($expiresAt) || \is_float($expiresAt) ? (int) $expiresAt : null,
        );
    }

    /**
     * @param array<array-key, mixed> $claims
     *
     * @return list<string>
     */
    private static function readAudience(array $claims): array
    {
        $aud = $claims['aud'] ?? [];

        if (\is_string($aud)) {
            return [$aud];
        }

        $audience = [];

        if (\is_array($aud)) {
            foreach ($aud as $entry) {
                if (\is_string($entry)) {
                    $audience[] = $entry;
                }
            }
        }

        return $audience;
    }

    /**
     * The granted scopes, from `scope` (a space-joined string, RFC 8693) or `scp` (a string or a
     * list, the Entra and Okta spellings).
     *
     * @param array<array-key, mixed> $claims
     *
     * @return list<non-empty-string>
     */
    private static function readScopes(array $claims): array
    {
        $scope = $claims['scope'] ?? $claims['scp'] ?? null;

        if (\is_string($scope)) {
            $scope = explode(' ', $scope);
        }

        if (! \is_array($scope)) {
            return [];
        }

        $scopes = [];

        foreach ($scope as $entry) {
            if (\is_string($entry) && '' !== $entry) {
                $scopes[] = $entry;
            }
        }

        return $scopes;
    }
}
