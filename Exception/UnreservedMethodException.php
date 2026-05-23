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
 * Thrown when a built-in handler is replaced for a method the MCP specification does not reserve.
 */
final class UnreservedMethodException extends \LogicException implements McpExceptionInterface
{
    public function __construct(string $method, bool $isNotification = false)
    {
        parent::__construct(\sprintf(
            '%s method "%s" is not reserved by the MCP specification. Use %s() to register a vendor extension.',
            $isNotification ? 'Notification' : 'Request',
            $method,
            $isNotification ? 'addNotificationHandler' : 'addRequestHandler',
        ));
    }
}
