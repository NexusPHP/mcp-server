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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `CompletionStoreInterface`.
 *
 * @phpstan-type ArgumentMap array<int|non-empty-string, (\Closure(string, ?array<array-key, string>, ServerContext): CompleteResult)|CompletionProviderInterface>
 * @phpstan-type ProviderMap array<int|non-empty-string, array<int|non-empty-string, CompletionProviderInterface>>
 */
final readonly class CompletionStore implements CompletionStoreInterface
{
    /**
     * @var ProviderMap
     */
    private array $promptCompletions;

    /**
     * @var ProviderMap
     */
    private array $templateCompletions;

    /**
     * @param array<int|non-empty-string, ArgumentMap> $promptCompletions
     * @param array<int|non-empty-string, ArgumentMap> $templateCompletions
     */
    public function __construct(array $promptCompletions = [], array $templateCompletions = [])
    {
        Assert::that($promptCompletions)
            ->keys()
            ->isIntOrNonEmptyString('Completion store prompt key must be a non-empty string.')
        ;
        Assert::that($templateCompletions)
            ->keys()
            ->isIntOrNonEmptyString('Completion store template key must be a non-empty string.')
        ;

        $this->promptCompletions = $this->normalize($promptCompletions);
        $this->templateCompletions = $this->normalize($templateCompletions);
    }

    #[\Override]
    public function complete(
        PromptReference|ResourceTemplateReference $ref,
        string $argumentName,
        string $argumentValue,
        ?array $contextArguments,
        ServerContext $context,
    ): CompleteResult {
        if ($ref instanceof PromptReference) {
            $providers = $this->promptCompletions[$ref->name] ?? null;
        } else {
            $providers = $this->templateCompletions[$ref->uri] ?? null;
        }

        if (null === $providers || ! isset($providers[$argumentName])) {
            return new CompleteResult(completion: ['values' => []]);
        }

        return $providers[$argumentName]->complete($argumentValue, $contextArguments, $context);
    }

    /**
     * @param array<int|non-empty-string, ArgumentMap> $completions
     *
     * @return ProviderMap
     */
    private function normalize(array $completions): array
    {
        $normalized = [];

        foreach ($completions as $key => $providers) {
            Assert::that($providers)
                ->keys()
                ->isIntOrNonEmptyString('Completion store argument key must be a non-empty string.')
            ;

            foreach ($providers as $argument => $provider) {
                if (! $provider instanceof CompletionProviderInterface) {
                    Assert::that($provider)->isInstanceOf(
                        \Closure::class,
                        'Completion provider must be a closure or implement CompletionProviderInterface, {type} given.',
                    );
                    $provider = new ClosureCompletionProvider($provider);
                }

                $normalized[$key][$argument] = $provider;
            }
        }

        return $normalized;
    }
}
