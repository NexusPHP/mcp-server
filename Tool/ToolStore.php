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

namespace Nexus\Mcp\Server\Tool;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;

/**
 * In-memory implementation of `MutableToolStoreInterface`.
 */
final class ToolStore implements MutableToolStoreInterface
{
    private readonly CursorPaginator $paginator;

    /**
     * @var list<\Closure(): void>
     */
    private array $listChangedListeners = [];

    /**
     * @param array<int|non-empty-string, ToolEntry> $entries
     */
    public function __construct(
        private array $entries = [],
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private readonly SchemaValidatorInterface $validator = new OpisSchemaValidator(),
        private readonly int $ttlMs = 0,
        private readonly CacheScope $cacheScope = CacheScope::Private,
    ) {
        foreach ($entries as $key => $entry) {
            // A decimal-int-string name arrives as an int key, so the comparison is on the stringified key.
            Assert::that($entry->tool->name)->isIdentical(
                (string) $key,
                'Tool store entry key "{other}" must match its tool name "{value}".',
            );
        }

        Assert::that($pageSize)
            ->isPositiveInt('Tool store page size must be a positive integer, {value} given.')
        ;
        Assert::that($ttlMs)
            ->isNaturalInt('Tool store TTL must be a non-negative integer, {value} given.')
        ;

        $this->paginator = new CursorPaginator($pageSize);
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        $this->listChangedListeners[] = $listener;
    }

    #[\Override]
    public function addTool(Tool $tool, ToolExecutorInterface $executor): void
    {
        $this->entries[$tool->name] = new ToolEntry($tool, $executor);

        $this->announceListChange();
    }

    #[\Override]
    public function removeTool(string $name): bool
    {
        if (! \array_key_exists($name, $this->entries)) {
            return false;
        }

        unset($this->entries[$name]);

        $this->announceListChange();

        return true;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListToolsResult(
            tools: array_map(static fn(ToolEntry $entry): Tool => $entry->tool, $page->entries),
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $page->nextCursor,
        );
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult|InputRequiredResult
    {
        $entry = $this->entries[$name] ?? throw new ToolNotFoundException($name, $context->requestId);

        $tool = $entry->tool;

        $inputData = null === $arguments || [] === $arguments ? new \stdClass() : $arguments;
        $inputErrors = $this->validator->validate($inputData, $tool->inputSchema);

        if ([] !== $inputErrors) {
            throw new InvalidParamsException(
                $context->requestId,
                \sprintf('Invalid arguments for tool "%s": %s', $name, implode('; ', $inputErrors)),
            );
        }

        $result = $entry->executor->execute($arguments, $context);

        if ($result instanceof InputRequiredResult) {
            // The round trip is unfinished, so there is no structured output to validate yet.
            return $result;
        }

        if (null !== $tool->outputSchema && true !== $result->isError && null !== $result->structuredContent) {
            $outputData = [] === $result->structuredContent ? new \stdClass() : $result->structuredContent;
            $outputErrors = $this->validator->validate($outputData, $tool->outputSchema);

            if ([] !== $outputErrors) {
                throw new ToolOutputValidationException($name, $outputErrors);
            }
        }

        return $result;
    }

    private function announceListChange(): void
    {
        foreach ($this->listChangedListeners as $listener) {
            $listener();
        }
    }
}
