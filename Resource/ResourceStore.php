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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\ResourceNotRegisteredException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `MutableResourceStoreInterface`.
 */
final class ResourceStore implements MutableResourceStoreInterface
{
    private readonly CursorPaginator $paginator;

    /**
     * @var list<\Closure(): void>
     */
    private array $listChangedListeners = [];

    /**
     * @param array<non-empty-string, ResourceEntry> $entries
     */
    public function __construct(
        private array $entries = [],
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private readonly int $ttlMs = 0,
        private readonly CacheScope $cacheScope = CacheScope::Private,
    ) {
        Assert::that($entries)
            ->keys()
            ->isNonEmptyString('Resource store entry key must be a non-empty string.')
        ;

        foreach ($entries as $key => $entry) {
            IdentifierNameValidator::validate($entry->resource->name, 'resource "name"');
            Assert::that($entry->resource->uri)
                ->isIdentical($key, 'Resource store entry key "{other}" must match its resource URI "{value}".')
            ;
        }
        Assert::that($pageSize)
            ->isPositiveInt('Resource store page size must be a positive integer, {value} given.')
        ;
        Assert::that($ttlMs)
            ->isNaturalInt('Resource store TTL must be a non-negative integer, {value} given.')
        ;

        $this->paginator = new CursorPaginator($pageSize);
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        $this->listChangedListeners[] = $listener;
    }

    #[\Override]
    public function addResource(Resource $resource, ResourceReaderInterface $reader): void
    {
        IdentifierNameValidator::validate($resource->name, 'resource "name"');

        $this->entries[$resource->uri] = new ResourceEntry($resource, $reader);

        $this->announceListChange();
    }

    #[\Override]
    public function removeResource(string $uri): bool
    {
        if (! \array_key_exists($uri, $this->entries)) {
            return false;
        }

        unset($this->entries[$uri]);

        $this->announceListChange();

        return true;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourcesResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListResourcesResult(
            resources: array_map(static fn(ResourceEntry $entry): Resource => $entry->resource, $page->entries),
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $page->nextCursor,
        );
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): InputRequiredResult|ReadResourceResult
    {
        $entry = $this->entries[$uri] ?? throw new ResourceNotRegisteredException($uri, $context->requestId);

        return $entry->reader->read($uri, $context);
    }

    private function announceListChange(): void
    {
        foreach ($this->listChangedListeners as $listener) {
            $listener();
        }
    }
}
