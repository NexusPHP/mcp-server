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
 * Adapts a closure to the `CompletionProviderInterface` contract.
 */
final readonly class ClosureCompletionProvider implements CompletionProviderInterface
{
    /**
     * @param \Closure(string, ?array<array-key, string>, ServerContext): CompleteResult $provider
     */
    public function __construct(private \Closure $provider)
    {
    }

    #[\Override]
    public function complete(string $argumentValue, ?array $contextArguments, ServerContext $context): CompleteResult
    {
        return ($this->provider)($argumentValue, $contextArguments, $context);
    }
}
