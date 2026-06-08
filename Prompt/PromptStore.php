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

namespace Nexus\Mcp\Server\Prompt;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `PromptStoreInterface`.
 *
 * @extends AbstractPaginatedStore<PromptEntry>
 */
final readonly class PromptStore extends AbstractPaginatedStore implements PromptStoreInterface
{
    protected const string STORE_LABEL = 'Prompt store';

    #[\Override]
    public function list(?Cursor $cursor): ListPromptsResult
    {
        return $this->paginate(
            $cursor,
            static fn(PromptEntry $entry): Prompt => $entry->prompt,
            static fn(array $prompts, ?Cursor $nextCursor, int $ttlMs, CacheScope $cacheScope): ListPromptsResult => new ListPromptsResult($prompts, $ttlMs, $cacheScope, $nextCursor),
        );
    }

    #[\Override]
    public function get(string $name, ?array $arguments, ServerContext $context): GetPromptResult
    {
        if (! \array_key_exists($name, $this->entries)) {
            throw new PromptNotFoundException($name, $context->requestId);
        }

        return $this->entries[$name]->renderer->render($arguments, $context);
    }
}
