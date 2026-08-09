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
 * Turns a bearer token into what it grants, owning the whole of validation and returning `null` for anything
 * it cannot verify, a token carrying no expiry included.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#token-handling
 */
interface AccessTokenValidatorInterface
{
    public function validate(string $token): ?VerifiedAccessToken;
}
