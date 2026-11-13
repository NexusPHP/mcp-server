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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
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

    /**
     * @param null|(\Closure(PromptReference|ResourceTemplateReference): bool) $isRegistered Whether the ref names a registered prompt, resource or template, or null to complete any ref
     */
    public function __construct(
        private CompletionStoreInterface $store,
        private ?\Closure $isRegistered = null,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): CompleteResult
    {
        \assert($request instanceof CompleteRequest);
        \assert($context instanceof ServerContext);

        $params = $request->params;
        $ref = $params->ref;

        // Completion: servers SHOULD answer an invalid prompt name with -32602.
        if (null !== $this->isRegistered && ! ($this->isRegistered)($ref)) {
            throw new InvalidParamsException($request->id, $ref instanceof PromptReference
                ? \sprintf('Prompt "%s" not found.', SafeDisplay::sanitise($ref->name))
                : \sprintf('Resource template "%s" not found.', SafeDisplay::sanitise($ref->uri)));
        }

        $result = $this->store->complete(
            $ref,
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
