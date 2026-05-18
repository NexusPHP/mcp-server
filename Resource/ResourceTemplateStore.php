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
     * @param array<non-empty-string, ResourceTemplateEntry> $entries
     */
    public function __construct(array $entries = [], int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        parent::__construct($entries, $pageSize);

        foreach ($this->entries as $key => $entry) {
            Validator::validate($key, 'ResourceTemplate');
            Assert::that($entry->template->uriTemplate)
                ->isIdentical($key, 'Resource template store entry key "{other}" must match its template URI "{value}".')
            ;
        }
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourceTemplatesResult
    {
        return $this->paginate(
            $cursor,
            static fn(ResourceTemplateEntry $entry): ResourceTemplate => $entry->template,
            static fn(array $templates, ?Cursor $nextCursor): ListResourceTemplatesResult => new ListResourceTemplatesResult($templates, $nextCursor),
        );
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): ReadResourceResult
    {
        foreach ($this->entries as $uriTemplate => $entry) {
            $bindings = Matcher::match($uriTemplate, $uri);

            if (null !== $bindings) {
                return $entry->reader->read($uri, $bindings, $context);
            }
        }

        throw new ResourceNotFoundException($uri, $context->requestId);
    }
}
