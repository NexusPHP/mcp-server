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

namespace Nexus\Mcp\Server\Resource;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Core\Validation\IconSrcValidator;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * In-memory implementation of `MutableResourceTemplateStoreInterface`.
 */
final class ResourceTemplateStore implements MutableResourceTemplateStoreInterface
{
    private readonly CursorPaginator $paginator;

    /**
     * Keyed by entry key so a removal drops the pattern with its entry, ordered by descending literal
     * length so the most specific template answers first.
     *
     * @var array<non-empty-string, array{pattern: non-empty-string, literals: int<0, max>, entry: ResourceTemplateEntry}>
     */
    private array $compiled = [];

    /**
     * @var list<\Closure(): void>
     */
    private array $listChangedListeners = [];

    /**
     * @param array<non-empty-string, ResourceTemplateEntry> $entries
     */
    public function __construct(
        private array $entries = [],
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private readonly int $ttlMs = 0,
        private readonly CacheScope $cacheScope = CacheScope::Private,
    ) {
        Assert::that($entries)
            ->keys()
            ->isNonEmptyString('Resource template store entry key must be a non-empty string.')
        ;

        foreach ($entries as $entry) {
            IdentifierNameValidator::validate($entry->template->name, 'resource template "name"');
            IconSrcValidator::validate($entry->template->icons, 'resource template');
        }

        Assert::that($pageSize)->isPositiveInt('Resource template store page size must be a positive integer, {value} given.');
        Assert::that($ttlMs)->isNaturalInt('Resource template store TTL must be a non-negative integer, {value} given.');

        $this->paginator = new CursorPaginator($pageSize);

        foreach ($entries as $key => $entry) {
            Assert::that($entry->template->uriTemplate)
                ->isIdentical($key, 'Resource template store entry key "{other}" must match its template URI "{value}".')
            ;

            $this->indexTemplate($key, $entry);
        }
    }

    #[\Override]
    public function onListChanged(\Closure $listener): void
    {
        $this->listChangedListeners[] = $listener;
    }

    #[\Override]
    public function addResourceTemplate(ResourceTemplate $template, TemplatedResourceReaderInterface $reader): void
    {
        IdentifierNameValidator::validate($template->name, 'resource template "name"');
        IconSrcValidator::validate($template->icons, 'resource template');

        $uriTemplate = $template->uriTemplate;
        $entry = new ResourceTemplateEntry($template, $reader);

        // Indexing validates, so it runs first: a rejected template must leave neither map touched.
        $this->indexTemplate($uriTemplate, $entry);
        $this->entries[$uriTemplate] = $entry;

        $this->announceListChange();
    }

    #[\Override]
    public function removeResourceTemplate(string $uriTemplate): bool
    {
        if (! \array_key_exists($uriTemplate, $this->entries)) {
            return false;
        }

        unset($this->entries[$uriTemplate], $this->compiled[$uriTemplate]);

        $this->announceListChange();

        return true;
    }

    #[\Override]
    public function list(?Cursor $cursor): ListResourceTemplatesResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListResourceTemplatesResult(
            resourceTemplates: array_map(
                static fn(ResourceTemplateEntry $entry): ResourceTemplate => $entry->template,
                $page->entries,
            ),
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $page->nextCursor,
        );
    }

    #[\Override]
    public function read(string $uri, ServerContext $context): InputRequiredResult|ReadResourceResult
    {
        foreach ($this->compiled as ['pattern' => $pattern, 'entry' => $entry]) {
            $bindings = Matcher::matchCompiled($pattern, $uri);

            if (null !== $bindings) {
                return $entry->reader->read($uri, $bindings, $context);
            }
        }

        throw new ResourceNotFoundException($uri, $context->requestId);
    }

    /**
     * @param non-empty-string $uriTemplate
     */
    private function indexTemplate(string $uriTemplate, ResourceTemplateEntry $entry): void
    {
        Validator::validate($uriTemplate, 'ResourceTemplate');
        $this->compiled[$uriTemplate] = [
            'pattern' => Matcher::compile($uriTemplate),
            'literals' => \strlen((string) preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', '', $uriTemplate)),
            'entry' => $entry,
        ];
        uasort($this->compiled, static fn(array $a, array $b): int => $b['literals'] <=> $a['literals']);
    }

    private function announceListChange(): void
    {
        foreach ($this->listChangedListeners as $listener) {
            $listener();
        }
    }
}
