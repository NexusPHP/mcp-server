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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;

/**
 * In-memory implementation of `ToolStoreInterface`.
 *
 * @extends AbstractPaginatedStore<ToolEntry>
 */
final readonly class ToolStore extends AbstractPaginatedStore implements ToolStoreInterface
{
    protected const string STORE_LABEL = 'Tool store';

    /**
     * @param array<non-empty-string, ToolEntry> $entries
     */
    public function __construct(
        array $entries = [],
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        private SchemaValidatorInterface $validator = new OpisSchemaValidator(),
        int $ttlMs = 0,
        CacheScope $cacheScope = CacheScope::Private,
    ) {
        parent::__construct($entries, $pageSize, $ttlMs, $cacheScope);
    }

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        return $this->paginate(
            $cursor,
            static fn(ToolEntry $entry): Tool => $entry->tool,
            static fn(array $tools, ?Cursor $nextCursor, int $ttlMs, CacheScope $cacheScope): ListToolsResult => new ListToolsResult($tools, $ttlMs, $cacheScope, $nextCursor),
        );
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult
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

        if (null !== $tool->outputSchema && true !== $result->isError && null !== $result->structuredContent) {
            $outputData = [] === $result->structuredContent ? new \stdClass() : $result->structuredContent;
            $outputErrors = $this->validator->validate($outputData, $tool->outputSchema);

            if ([] !== $outputErrors) {
                throw new ToolOutputValidationException($name, $outputErrors);
            }
        }

        return $result;
    }
}
