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
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Read surface that the built-in `resources/templates/*` request handlers depend on.
 */
interface ResourceTemplateStoreInterface
{
    /**
     * @throws InvalidCursorException
     */
    public function list(?Cursor $cursor): ListResourceTemplatesResult;

    /**
     * Reads `$uri` against the registered templates, returning the result of the
     * first template that matches.
     *
     * @param non-empty-string $uri
     *
     * @throws ResourceNotFoundException
     */
    public function read(string $uri, ServerContext $context): InputRequiredResult|ReadResourceResult;
}
