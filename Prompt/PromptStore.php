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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `MutablePromptStoreInterface`.
 */
final class PromptStore implements MutablePromptStoreInterface
{
    private readonly CursorPaginator $paginator;

    /**
     * @var list<\Closure(): void>
     */
    private array $listChangedListeners = [];

    /**
     * @param array<array-key, PromptEntry> $entries
     */
    public function __construct(
        private array $entries = [],
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private readonly int $ttlMs = 0,
        private readonly CacheScope $cacheScope = CacheScope::Private,
    ) {
        foreach ($entries as $key => $entry) {
            // A decimal-int-string name arrives as an int key, so the comparison is on the stringified key.
            Assert::that($entry->prompt->name)->isIdentical(
                (string) $key,
                'Prompt store entry key "{other}" must match its prompt name "{value}".',
            );
        }

        Assert::that($pageSize)
            ->isPositiveInt('Prompt store page size must be a positive integer, {value} given.')
        ;
        Assert::that($ttlMs)
            ->isNaturalInt('Prompt store TTL must be a non-negative integer, {value} given.')
        ;

        $this->paginator = new CursorPaginator($pageSize);
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        $this->listChangedListeners[] = $listener;
    }

    #[\Override]
    public function addPrompt(Prompt $prompt, PromptRendererInterface $renderer): void
    {
        $this->entries[$prompt->name] = new PromptEntry($prompt, $renderer);

        $this->announceListChange();
    }

    #[\Override]
    public function removePrompt(string $name): bool
    {
        if (! \array_key_exists($name, $this->entries)) {
            return false;
        }

        unset($this->entries[$name]);

        $this->announceListChange();

        return true;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListPromptsResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListPromptsResult(
            prompts: array_map(static fn(PromptEntry $entry): Prompt => $entry->prompt, $page->entries),
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $page->nextCursor,
        );
    }

    #[\Override]
    public function get(string $name, ?array $arguments, ServerContext $context): GetPromptResult|InputRequiredResult
    {
        if (! \array_key_exists($name, $this->entries)) {
            throw new PromptNotFoundException($name, $context->requestId);
        }

        return $this->entries[$name]->renderer->render($arguments, $context);
    }

    private function announceListChange(): void
    {
        foreach ($this->listChangedListeners as $listener) {
            $listener();
        }
    }
}
