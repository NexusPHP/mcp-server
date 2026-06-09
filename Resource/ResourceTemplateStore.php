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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `ResourceTemplateStoreInterface`.
 *
 * @extends AbstractPaginatedStore<ResourceTemplateEntry>
 */
final readonly class ResourceTemplateStore extends AbstractPaginatedStore implements ResourceTemplateStoreInterface
{
    protected const string STORE_LABEL = 'Resource template store';

    /**
     * @var list<array{pattern: non-empty-string, entry: ResourceTemplateEntry}>
     */
    private array $compiled;

    /**
     * @param array<non-empty-string, ResourceTemplateEntry> $entries
     */
    public function __construct(
        array $entries = [],
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        int $ttlMs = 0,
        CacheScope $cacheScope = CacheScope::Private,
    ) {
        parent::__construct($entries, $pageSize, $ttlMs, $cacheScope);

        $compiled = [];

        foreach ($this->entries as $key => $entry) {
            Validator::validate($key, 'ResourceTemplate');
            Assert::that($entry->template->uriTemplate)
                ->isIdentical($key, 'Resource template store entry key "{other}" must match its template URI "{value}".')
            ;
            $compiled[] = ['pattern' => Matcher::compile($key), 'entry' => $entry];
        }

        $this->compiled = $compiled;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourceTemplatesResult
    {
        return $this->paginate(
            $cursor,
            static fn(ResourceTemplateEntry $entry): ResourceTemplate => $entry->template,
            self::buildResult(...),
        );
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): ReadResourceResult
    {
        foreach ($this->compiled as ['pattern' => $pattern, 'entry' => $entry]) {
            $bindings = Matcher::matchCompiled($pattern, $uri);

            if (null !== $bindings) {
                return $entry->reader->read($uri, $bindings, $context);
            }
        }

        throw new ResourceNotFoundException($uri, $context->requestId);
    }

    /**
     * @param list<ResourceTemplate> $templates
     */
    private static function buildResult(array $templates, ?Cursor $nextCursor, int $ttlMs, CacheScope $cacheScope): ListResourceTemplatesResult
    {
        return new ListResourceTemplatesResult(
            resourceTemplates: $templates,
            ttlMs: $ttlMs,
            cacheScope: $cacheScope,
            nextCursor: $nextCursor,
        );
    }
}
