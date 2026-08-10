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
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;

/**
 * JWT bearer token validator over a key set, needing the suggested `firebase/php-jwt` package.
 */
final readonly class JwksAccessTokenValidator implements AccessTokenValidatorInterface
{
    /**
     * @param array<string, Key>|\ArrayAccess<string, Key> $keys           Keys by `kid`, typically a `Firebase\JWT\CachedKeySet`
     * @param non-empty-string                             $expectedIssuer The `iss` every accepted token must carry
     */
    public function __construct(private array|\ArrayAccess $keys, private string $expectedIssuer)
    {
        SuggestedDependencyGuard::verify(self::class, JWT::class, 'firebase/php-jwt', '^7.0');
        Assert::that($expectedIssuer)->isNonEmptyString('JWKS validator expected issuer must be a non-empty string, {type} given.');
    }

    #[\Override]
    public function validate(string $token): ?VerifiedAccessToken
    {
        $claims = $this->decode($token);

        if (null === $claims) {
            return null;
        }

        $audience = self::readAudience($claims);

        if (($claims['iss'] ?? null) !== $this->expectedIssuer) {
            return null;
        }

        $expiresAt = $claims['exp'] ?? null;

        // `JWT::decode` checks expiry only when the claim is present, so absence is what must be refused.
        if (! is_numeric($expiresAt)) {
            return null;
        }

        $subject = $claims['sub'] ?? null;

        return new VerifiedAccessToken(
            audience: $audience,
            scopes: self::readScopes($claims),
            subject: \is_string($subject) && '' !== $subject ? $subject : null,
            clientId: self::readClientId($claims),
            expiresAt: (int) $expiresAt,
        );
    }

    /**
     * @return null|array<array-key, mixed>
     */
    private function decode(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, $this->keys);
        } catch (\Exception) {
            return null;
        }
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
     * The client the token names, from `azp`, `client_id` or `cid`, skipping any that names nobody.
     *
     * @param array<array-key, mixed> $claims
     *
     * @return null|non-empty-string
     */
    private static function readClientId(array $claims): ?string
    {
        foreach (['azp', 'client_id', 'cid'] as $claim) {
            $candidate = $claims[$claim] ?? null;

            if (\is_string($candidate) && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
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
