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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;

/**
 * In-memory implementation of {@see ResourceTemplateStoreInterface}.
 */
final readonly class ResourceTemplateStore implements ResourceTemplateStoreInterface
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, ResourceTemplate> $templates
     */
    public function __construct(private array $templates = [], private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        Assert::that($this->templates)
            ->keys()
            ->isNonEmptyString('Resource template store entry key must be a non-empty string.')
        ;
        Assert::that($this->pageSize)
            ->isPositiveInt('Resource template store page size must be a positive integer, {value} given.')
        ;

        $this->keyIndex = array_flip(array_keys($this->templates));
    }

    #[\Override]
    public function listTemplates(?Cursor $cursor): ListResourceTemplatesResult
    {
        $startIndex = $this->startIndexFor($cursor);
        $page = \array_slice($this->templates, $startIndex, $this->pageSize);
        $templates = array_values($page);

        $hasMore = $startIndex + \count($page) < \count($this->templates);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return new ListResourceTemplatesResult($templates, $nextCursor);
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
