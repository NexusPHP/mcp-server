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
 * @phpstan-type ArgumentMap array<non-empty-string, \Closure(string, ?array<string, string>, ServerContext): CompleteResult>
 */
final readonly class CompletionStore implements CompletionStoreInterface
{
    /**
     * @param array<non-empty-string, ArgumentMap> $promptCompletions
     * @param array<non-empty-string, ArgumentMap> $templateCompletions
     */
    public function __construct(private array $promptCompletions = [], private array $templateCompletions = [])
    {
        Assert::that($this->promptCompletions)
            ->keys()
            ->isNonEmptyString('Completion store prompt key must be a non-empty string.')
        ;
        Assert::that($this->templateCompletions)
            ->keys()
            ->isNonEmptyString('Completion store template key must be a non-empty string.')
        ;
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

        return ($providers[$argumentName])($argumentValue, $contextArguments, $context);
    }
}
