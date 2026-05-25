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

namespace Nexus\Mcp\Server\Prompt;

use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Discovery\ArgumentBinder;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts an attribute-discovered handler method to the `PromptRendererInterface` contract.
 */
final readonly class ReflectedPromptRenderer implements PromptRendererInterface
{
    public function __construct(
        private object $handler,
        private \ReflectionMethod $method,
        private ArgumentBinder $binder = new ArgumentBinder(),
    ) {
    }

    #[\Override]
    public function render(?array $arguments, ServerContext $context): GetPromptResult
    {
        $bound = $this->binder->bind($this->method, $arguments ?? [], $context);

        return $this->adapt($this->method->invokeArgs($this->handler, $bound));
    }

    private function adapt(mixed $result): GetPromptResult
    {
        if ($result instanceof GetPromptResult) {
            return $result;
        }

        if (\is_string($result)) {
            return new GetPromptResult([new PromptMessage(Role::User, new TextContent($result))]);
        }

        if ($result instanceof PromptMessage) {
            return new GetPromptResult([$result]);
        }

        if (\is_array($result)) {
            return new GetPromptResult($this->buildMessageList($result));
        }

        throw new UnsupportedReturnValueException(
            $this->method->getDeclaringClass()->getName(),
            $this->method->getName(),
            \sprintf('a %s, a string, or prompt messages', GetPromptResult::class),
            $result,
        );
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return list<PromptMessage>
     */
    private function buildMessageList(array $result): array
    {
        $messages = array_filter($result, static fn(mixed $item): bool => $item instanceof PromptMessage);

        if (! array_is_list($result) || [] === $result || \count($messages) !== \count($result)) {
            throw new UnsupportedReturnValueException(
                $this->method->getDeclaringClass()->getName(),
                $this->method->getName(),
                \sprintf('a %s, a string, or prompt messages', GetPromptResult::class),
                $result,
            );
        }

        return array_values($messages);
    }
}
