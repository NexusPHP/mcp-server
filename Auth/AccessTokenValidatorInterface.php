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

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;

/**
 * Turns a bearer token into what it grants. The SDK ships no signature or introspection machinery, so a host
 * supplies the verification its authorization server calls for.
 *
 * An implementation owns the whole of token validation, signature or introspection and expiry included, and
 * rejects a token it cannot verify by returning `null`. Only two checks are not its job:
 * `BearerAuthenticationMiddleware` binds the returned audience to this server and enforces the scopes the
 * endpoint requires.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/authorization#token-handling
 */
interface AccessTokenValidatorInterface
{
    public function validate(string $token): ?VerifiedAccessToken;
}
