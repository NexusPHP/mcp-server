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

namespace Nexus\Mcp\Server\Handler\Request;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `logging/setLevel` request by updating the server's minimum log
 * level so subsequent `ServerContext::log()` calls below the threshold are
 * dropped before reaching the transport.
 *
 * @implements RequestHandlerInterface<'logging/setLevel', EmptyResult, ServerContext>
 */
final readonly class SetLevelRequestHandler implements RequestHandlerInterface
{
    public function __construct(private LoggingLevelGate $gate)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): EmptyResult
    {
        \assert($request instanceof SetLevelRequest);

        $this->gate->setLevel($request->params->level);

        return new EmptyResult();
    }
}
