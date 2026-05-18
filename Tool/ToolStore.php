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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `ToolStoreInterface`.
 *
 * @extends AbstractPaginatedStore<ToolEntry>
 */
final readonly class ToolStore extends AbstractPaginatedStore implements ToolStoreInterface
{
    protected const string STORE_LABEL = 'Tool store';

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        return $this->paginate(
            $cursor,
            static fn(ToolEntry $entry): Tool => $entry->tool,
            static fn(array $tools, ?Cursor $nextCursor): ListToolsResult => new ListToolsResult($tools, $nextCursor),
        );
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult
    {
        if (! \array_key_exists($name, $this->entries)) {
            throw new ToolNotFoundException($name, $context->requestId);
        }

        return $this->entries[$name]->executor->execute($arguments, $context);
    }
}
