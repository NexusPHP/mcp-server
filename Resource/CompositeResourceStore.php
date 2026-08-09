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
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ListChangeSourceInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Chains a primary `ResourceStoreInterface` (exact-URI matches) with a fallback
 * `ResourceTemplateStoreInterface` (URI-template matches).
 */
final readonly class CompositeResourceStore implements ListChangeSourceInterface, ResourceStoreInterface
{
    public function __construct(private ResourceStoreInterface $resourceStore, private ResourceTemplateStoreInterface $resourceTemplateStore)
    {
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        foreach ([$this->resourceStore, $this->resourceTemplateStore] as $store) {
            if ($store instanceof ListChangeSourceInterface) {
                $store->onListChanged($listener);
            }
        }
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourcesResult
    {
        return $this->resourceStore->list($cursor);
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): InputRequiredResult|ReadResourceResult
    {
        try {
            return $this->resourceStore->read($uri, $context);
        } catch (ResourceNotFoundException) {
            return $this->resourceTemplateStore->read($uri, $context);
        }
    }
}
