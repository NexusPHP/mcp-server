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

use Nexus\Mcp\Server\Exception\MissingSuggestedDependencyException;

/**
 * Verifies a suggested package is installed before the class that needs it does any work.
 *
 * @internal
 */
final class SuggestedDependencyGuard
{
    /**
     * @param class-string     $consumer   The class that needs the package
     * @param string           $class      A class the package autoloads, probed via `class_exists`
     * @param non-empty-string $package    The composer package name
     * @param non-empty-string $constraint The version constraint to install with
     *
     * @throws MissingSuggestedDependencyException
     */
    public static function verify(string $consumer, string $class, string $package, string $constraint): void
    {
        if (class_exists($class)) {
            return;
        }

        throw new MissingSuggestedDependencyException(\sprintf(
            '%s requires the suggested "%s" package. Install it with "composer require %s:%s".',
            $consumer,
            $package,
            $package,
            $constraint,
        ));
    }
}
