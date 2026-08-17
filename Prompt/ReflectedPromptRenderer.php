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
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
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
    public function render(?array $arguments, ServerContext $context): GetPromptResult|InputRequiredResult
    {
        $bound = $this->binder->bind($this->method, $arguments ?? [], $context);

        return $this->adapt($this->method->invokeArgs($this->handler, $bound));
    }

    private function adapt(mixed $result): GetPromptResult|InputRequiredResult
    {
        if ($result instanceof GetPromptResult || $result instanceof InputRequiredResult) {
            return $result;
        }

        if (\is_string($result)) {
            return new GetPromptResult(messages: [
                new PromptMessage(role: Role::User, content: new TextContent(text: $result)),
            ]);
        }

        if ($result instanceof PromptMessage) {
            return new GetPromptResult(messages: [$result]);
        }

        if (\is_array($result)) {
            return new GetPromptResult(messages: $this->buildMessageList($result));
        }

        throw self::buildUnsupportedError($this->method, $result);
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
            throw self::buildUnsupportedError($this->method, $result);
        }

        return array_values($messages);
    }

    private static function buildUnsupportedError(\ReflectionMethod $method, mixed $result): UnsupportedReturnValueException
    {
        return new UnsupportedReturnValueException(
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            \sprintf('a %s, a string, or prompt messages', GetPromptResult::class),
            $result,
        );
    }
}
