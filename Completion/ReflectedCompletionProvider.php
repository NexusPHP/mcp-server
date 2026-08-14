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

namespace Nexus\Mcp\Server\Completion;

use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Discovery\InputSchemaGenerator;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Adapts an attribute-discovered method to the `CompletionProviderInterface` contract.
 */
final readonly class ReflectedCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private object $handler,
        private \ReflectionMethod $method,
    ) {
    }

    #[\Override]
    public function complete(string $argumentValue, ?array $contextArguments, ServerContext $context): CompleteResult
    {
        $arguments = [];

        foreach ($this->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (InputSchemaGenerator::isInjectedContext($parameter)) {
                $arguments[] = $context;
            } elseif ($type instanceof \ReflectionNamedType && $type->getName() === 'array') {
                $arguments[] = $contextArguments ?? ($type->allowsNull() ? null : []);
            } else {
                $arguments[] = $argumentValue;
            }
        }

        return $this->adapt($this->method->invokeArgs($this->handler, $arguments));
    }

    private function adapt(mixed $result): CompleteResult
    {
        if ($result instanceof CompleteResult) {
            return $result;
        }

        if (\is_array($result) && array_is_list($result)) {
            $values = [];

            foreach ($result as $entry) {
                if (! \is_string($entry)) {
                    $values = null;

                    break;
                }

                $values[] = $entry;
            }

            if (null !== $values) {
                return new CompleteResult(completion: ['values' => $values]);
            }
        }

        throw new UnsupportedReturnValueException(
            $this->method->getDeclaringClass()->getName(),
            $this->method->getName(),
            \sprintf('a %s or a list of strings', CompleteResult::class),
            $result,
        );
    }
}
