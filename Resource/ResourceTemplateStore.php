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
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `ResourceTemplateStoreInterface`.
 *
 * @phpstan-type TemplateEntry array{template: ResourceTemplate, reader: TemplatedResourceReaderInterface}
 */
final readonly class ResourceTemplateStore implements ResourceTemplateStoreInterface
{
    public const int DEFAULT_PAGE_SIZE = 50;

    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $keyIndex;

    /**
     * @param array<non-empty-string, TemplateEntry> $entries
     */
    public function __construct(private array $entries = [], private int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        Assert::that($this->entries)
            ->keys()
            ->isNonEmptyString('Resource template store entry key must be a non-empty string.')
        ;
        Assert::that($this->pageSize)
            ->isPositiveInt('Resource template store page size must be a positive integer, {value} given.')
        ;

        foreach ($this->entries as $key => $entry) {
            Validator::validate($key, 'ResourceTemplate');
            Assert::that($entry['template']->uriTemplate)
                ->isIdentical($key, \sprintf(
                    'Resource template store entry key "%s" must match its template URI "%s".',
                    $key,
                    $entry['template']->uriTemplate,
                ))
            ;
        }

        $this->keyIndex = array_flip(array_keys($this->entries));
    }

    #[\Override]
    public function listTemplates(?Cursor $cursor): ListResourceTemplatesResult
    {
        $startIndex = $this->startIndexFor($cursor);
        $page = \array_slice($this->entries, $startIndex, $this->pageSize);
        $templates = array_values(array_map(static fn(array $entry): ResourceTemplate => $entry['template'], $page));

        $hasMore = $startIndex + \count($page) < \count($this->entries);
        $nextCursor = $hasMore ? new Cursor((string) array_key_last($page)) : null;

        return new ListResourceTemplatesResult($templates, $nextCursor);
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): ReadResourceResult
    {
        foreach ($this->entries as $uriTemplate => $entry) {
            $bindings = Matcher::match($uriTemplate, $uri);

            if (null !== $bindings) {
                return $entry['reader']->read($uri, $bindings, $context);
            }
        }

        throw new ResourceNotFoundException($uri, $context->requestId);
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
