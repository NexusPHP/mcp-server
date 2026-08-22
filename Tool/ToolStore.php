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

namespace Nexus\Mcp\Server\Tool;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Validation\IconSrcValidator;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;
use Nexus\Mcp\Server\Validation\SchemaViolation;

/**
 * In-memory implementation of `MutableToolStoreInterface`.
 */
final class ToolStore implements MutableToolStoreInterface
{
    private const int MAX_REPORTED_VIOLATIONS = 8;

    private readonly CursorPaginator $paginator;

    /**
     * @var list<\Closure(): void>
     */
    private array $listChangedListeners = [];

    /**
     * @param array<int|non-empty-string, ToolEntry> $entries
     */
    public function __construct(
        private array $entries = [],
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private readonly SchemaValidatorInterface $validator = new OpisSchemaValidator(),
        private readonly int $ttlMs = 0,
        private readonly CacheScope $cacheScope = CacheScope::Private,
    ) {
        foreach ($entries as $key => $entry) {
            IdentifierNameValidator::validate($entry->tool->name, 'tool "name"');
            IconSrcValidator::validate($entry->tool->icons, 'tool');
            Assert::that($entry->tool->name)->isIdentical(
                (string) $key,
                'Tool store entry key "{other}" must match its tool name "{value}".',
            );
        }

        Assert::that($pageSize)
            ->isPositiveInt('Tool store page size must be a positive integer, {value} given.')
        ;
        Assert::that($ttlMs)
            ->isNaturalInt('Tool store TTL must be a non-negative integer, {value} given.')
        ;

        $this->paginator = new CursorPaginator($pageSize);
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        $this->listChangedListeners[] = $listener;
    }

    #[\Override]
    public function addTool(Tool $tool, ToolExecutorInterface $executor): void
    {
        IdentifierNameValidator::validate($tool->name, 'tool "name"');
        IconSrcValidator::validate($tool->icons, 'tool');

        $this->entries[$tool->name] = new ToolEntry($tool, $executor);

        $this->announceListChange();
    }

    #[\Override]
    public function removeTool(string $name): bool
    {
        if (! \array_key_exists($name, $this->entries)) {
            return false;
        }

        unset($this->entries[$name]);

        $this->announceListChange();

        return true;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListToolsResult(
            tools: array_map(static fn(ToolEntry $entry): Tool => $entry->tool, $page->entries),
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $page->nextCursor,
        );
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult|InputRequiredResult
    {
        $entry = $this->entries[$name] ?? throw new ToolNotFoundException($name, $context->requestId);

        $tool = $entry->tool;

        $encoded = $tool->jsonSerialize();
        $inputData = null === $arguments || [] === $arguments ? new \stdClass() : $arguments;

        if (\is_array($inputData) && array_is_list($inputData)) {
            $inputData = (object) $inputData;
        }

        $inputErrors = $this->validator->validate($inputData, (array) $encoded['inputSchema']);

        if ([] !== $inputErrors) {
            throw new InvalidParamsException(
                $context->requestId,
                SafeDisplay::sanitiseCause(
                    \sprintf('Invalid arguments for tool "%s": %s', $name, implode(' ', array_map(
                        static fn(SchemaViolation $violation): string => $violation->message,
                        $inputErrors,
                    ))),
                ),
                errorData: ['validation_errors' => $this->describeViolations($inputErrors)],
            );
        }

        try {
            $result = $entry->executor->execute($arguments, $context);
        } catch (InvalidParamsException $e) {
            throw new InvalidParamsException(
                $context->requestId,
                SafeDisplay::sanitiseCause(\sprintf('Invalid arguments for tool "%s": %s', $name, $e->getMessage())),
            );
        }

        if ($result instanceof InputRequiredResult) {
            return $result;
        }

        if (null !== $tool->outputSchema && true !== $result->isError) {
            if (null === $result->structuredContent) {
                throw new ToolOutputValidationException($name, []);
            }

            $outputData = $result->structuredContent;

            if ([] === $outputData && ! $this->acceptsArray($tool->outputSchema)) {
                $outputData = new \stdClass();
            }

            $outputErrors = $this->validator->validate($outputData, (array) ($encoded['outputSchema'] ?? []));

            if ([] !== $outputErrors) {
                throw new ToolOutputValidationException($name, $outputErrors);
            }
        }

        return $result;
    }

    /**
     * @param non-empty-list<SchemaViolation> $violations
     *
     * @return non-empty-list<array{pointer: string, message: string}>
     */
    private function describeViolations(array $violations): array
    {
        $described = [];

        foreach (\array_slice($violations, 0, self::MAX_REPORTED_VIOLATIONS) as $violation) {
            $described[] = [
                'pointer' => SafeDisplay::sanitiseCause($violation->pointer),
                'message' => SafeDisplay::sanitiseCause($violation->message),
            ];
        }

        return $described;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function acceptsArray(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return 'array' === $type || (\is_array($type) && \in_array('array', $type, true));
    }

    private function announceListChange(): void
    {
        foreach ($this->listChangedListeners as $listener) {
            $listener();
        }
    }
}
