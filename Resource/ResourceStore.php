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

namespace Nexus\Mcp\Server\Resource;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `ResourceStoreInterface`.
 *
 * @extends AbstractPaginatedStore<ResourceEntry>
 */
final readonly class ResourceStore extends AbstractPaginatedStore implements ResourceStoreInterface
{
    protected const string STORE_LABEL = 'Resource store';

    #[\Override]
    public function list(?Cursor $cursor): ListResourcesResult
    {
        return $this->paginate(
            $cursor,
            static fn(ResourceEntry $entry): Resource => $entry->resource,
            static fn(array $resources, ?Cursor $nextCursor): ListResourcesResult => new ListResourcesResult($resources, $nextCursor),
        );
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): ReadResourceResult
    {
        $entry = $this->entries[$uri] ?? throw new ResourceNotFoundException($uri, $context->requestId);

        return $entry->reader->read($uri, $context);
    }
}
