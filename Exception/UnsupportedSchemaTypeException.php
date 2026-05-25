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

namespace Nexus\Mcp\Server\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when a PHP type cannot be mapped to a JSON Schema fragment.
 */
final class UnsupportedSchemaTypeException extends \LogicException implements McpExceptionInterface
{
    public function __construct(string $type)
    {
        parent::__construct(\sprintf('Type "%s" is not supported by the input schema generator.', $type));
    }
}
