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

namespace Nexus\Mcp\Server\Completion;

use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Completes one prompt argument or resource template variable.
 */
interface CompletionProviderInterface
{
    /**
     * @param string                        $argumentValue    Partial value the user has typed so far
     * @param null|array<array-key, string> $contextArguments Values the client has already resolved for the other arguments
     */
    public function complete(string $argumentValue, ?array $contextArguments, ServerContext $context): CompleteResult;
}
