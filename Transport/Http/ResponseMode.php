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

namespace Nexus\Mcp\Server\Transport\Http;

/**
 * How the Streamable HTTP transport answers a POST that dispatches to a handler.
 */
enum ResponseMode
{
    /**
     * Always buffer the final response into a single JSON object, dropping progress notifications.
     */
    case Json;

    /**
     * Always answer with a request-scoped SSE stream carrying progress notifications and the final response.
     */
    case Sse;

    /**
     * Buffer a JSON response, upgrading to an SSE stream the moment a progress notification arrives mid-call.
     */
    case Auto;
}
