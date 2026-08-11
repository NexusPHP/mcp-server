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
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\CompletionStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `completion/complete` request by delegating to a `CompletionStoreInterface`.
 *
 * @implements RequestHandlerInterface<'completion/complete', CompleteResult, ServerContext>
 */
final readonly class CompleteRequestHandler implements RequestHandlerInterface
{
    /**
     * CompleteResult schema on `values`: "Must not exceed 100 items.".
     */
    private const int MAX_VALUES = 100;

    public function __construct(private CompletionStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): CompleteResult
    {
        \assert($request instanceof CompleteRequest);
        \assert($context instanceof ServerContext);

        $params = $request->params;

        $result = $this->store->complete(
            $params->ref,
            $params->argument['name'],
            $params->argument['value'],
            $params->context['arguments'] ?? null,
            $context,
        );

        $values = $result->completion['values'];

        if (\count($values) <= self::MAX_VALUES) {
            return $result;
        }

        return new CompleteResult(
            completion: [
                'values' => \array_slice($values, 0, self::MAX_VALUES),
                'total' => $result->completion['total'] ?? \count($values),
                'hasMore' => true,
            ],
            meta: $result->meta,
        );
    }
}
