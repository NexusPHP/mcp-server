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
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `ResourceStoreInterface`.
 */
final readonly class ResourceStore implements ResourceStoreInterface
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, ResourceEntry> $entries
     */
    public function __construct(private array $entries = [], private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        Assert::that($this->entries)
            ->keys()
            ->isNonEmptyString('Resource store entry key must be a non-empty string.')
        ;
        Assert::that($this->pageSize)
            ->isPositiveInt('Resource store page size must be a positive integer, {value} given.')
        ;

        $this->keyIndex = array_flip(array_keys($this->entries));
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourcesResult
    {
        $startIndex = $this->startIndexFor($cursor);
        $page = \array_slice($this->entries, $startIndex, $this->pageSize);
        $resources = array_values(array_map(static fn(ResourceEntry $entry): Resource => $entry->resource, $page));

        $hasMore = $startIndex + \count($page) < \count($this->entries);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return new ListResourcesResult($resources, $nextCursor);
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): ReadResourceResult
    {
        if (! \array_key_exists($uri, $this->entries)) {
            throw new ResourceNotFoundException($uri, $context->requestId);
        }

        return $this->entries[$uri]->reader->read($uri, $context);
    }

    private function startIndexFor(?Cursor $cursor): int
    {
        if (null === $cursor) {
            return 0;
        }

        $raw = $cursor->cursor;

        if (! isset($this->keyIndex[$raw])) {
            throw new InvalidCursorException($raw);
        }

        return $this->keyIndex[$raw] + 1;
    }
}
