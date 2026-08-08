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

use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Read surface that the built-in `completion/complete` request handler depends on.
 */
interface CompletionStoreInterface
{
    /**
     * @param null|array<array-key, string> $contextArguments
     */
    public function complete(
        PromptReference|ResourceTemplateReference $ref,
        string $argumentName,
        string $argumentValue,
        ?array $contextArguments,
        ServerContext $context,
    ): CompleteResult;
}
