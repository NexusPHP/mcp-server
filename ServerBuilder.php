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

namespace Nexus\Mcp\Server;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Exception\DuplicateExtensionException;
use Nexus\Mcp\Core\Exception\ExtensionMethodCollisionException;
use Nexus\Mcp\Core\Extension\ExtensionCollection;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\Notification\CancelledNotificationHandler;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMethodRegistry;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Core\Validation\MethodClassValidator;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Completion\ClosureCompletionProvider;
use Nexus\Mcp\Server\Completion\CompletionProviderInterface;
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\Completion\CompletionStoreInterface;
use Nexus\Mcp\Server\Completion\PromptCompletionEntry;
use Nexus\Mcp\Server\Discovery\AttributeScanner;
use Nexus\Mcp\Server\Dispatch\ServerMessageDispatcher;
use Nexus\Mcp\Server\Exception\BuilderAlreadyBuiltException;
use Nexus\Mcp\Server\Exception\DuplicateServerMetadataException;
use Nexus\Mcp\Server\Exception\MissingDiscoveryAttributeException;
use Nexus\Mcp\Server\Exception\ReservedMethodException;
use Nexus\Mcp\Server\Exception\UnreservedMethodException;
use Nexus\Mcp\Server\Handler\Request\CallToolRequestHandler;
use Nexus\Mcp\Server\Handler\Request\CompleteRequestHandler;
use Nexus\Mcp\Server\Handler\Request\DiscoverRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ExtensionGateRequestHandler;
use Nexus\Mcp\Server\Handler\Request\GetPromptRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListPromptsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourcesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourceTemplatesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListToolsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ReadResourceRequestHandler;
use Nexus\Mcp\Server\Handler\Request\SubscriptionsListenRequestHandler;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\PromptRendererInterface;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\Prompt\PromptStoreInterface;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\CompositeResourceStore;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceReaderInterface;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceStoreInterface;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateStoreInterface;
use Nexus\Mcp\Server\Resource\TemplatedResourceReaderInterface;
use Nexus\Mcp\Server\Subscription\SubscriptionStoreInterface;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolExecutorInterface;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder that wires the per-feature stores, the dispatch kernel, and
 * the lifecycle shell into a runnable `Server` instance.
 *
 * @phpstan-import-type RequestHandlerDecorator from RequestHandlerDecoratorInterface
 */
final class ServerBuilder
{
    private ?Implementation $serverInfo = null;

    /**
     * @var null|non-empty-string
     */
    private ?string $instructions = null;

    private ?AsServer $serverMetadata = null;
    private ServerInfoDisclosure $serverInfoDisclosure = ServerInfoDisclosure::Full;

    /**
     * @var null|int<1, max>
     */
    private ?int $maxInFlight = null;

    private LoggerInterface $logger;
    private SchemaValidatorInterface $schemaValidator;

    /**
     * @var array<non-empty-string, ToolEntry>
     */
    private array $tools = [];

    /**
     * @var array<non-empty-string, PromptEntry>
     */
    private array $prompts = [];

    /**
     * @var array<non-empty-string, ResourceEntry>
     */
    private array $resources = [];

    /**
     * @var array<non-empty-string, ResourceTemplateEntry>
     */
    private array $resourceTemplates = [];

    /**
     * @var array<non-empty-string, array<non-empty-string, CompletionProviderInterface>>
     */
    private array $promptCompletions = [];

    /**
     * @var array<non-empty-string, array<non-empty-string, CompletionProviderInterface>>
     */
    private array $templateCompletions = [];

    private int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE;
    private int $ttlMs = 0;
    private CacheScope $cacheScope = CacheScope::Private;
    private ?ToolStoreInterface $toolStore = null;
    private ?PromptStoreInterface $promptStore = null;
    private ?ResourceStoreInterface $resourceStore = null;
    private ?ResourceTemplateStoreInterface $resourceTemplateStore = null;
    private ?CompletionStoreInterface $completionStore = null;
    private ?SubscriptionStoreInterface $subscriptionStore = null;
    private bool $built = false;

    /**
     * @var array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private array $customRequestHandlers = [];

    /**
     * @var array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    private array $customNotificationHandlers = [];

    /**
     * @var array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    private array $customRequestClasses = [];

    /**
     * @var array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    private array $customNotificationClasses = [];

    /**
     * @var ExtensionCollection<ServerContext>
     */
    private readonly ExtensionCollection $extensions;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->schemaValidator = new OpisSchemaValidator();
        $this->extensions = new ExtensionCollection();
    }

    /**
     * @param null|list<Icon> $icons
     */
    public function setServerInfo(
        string $name,
        string $version,
        ?string $title = null,
        ?string $description = null,
        ?string $websiteUrl = null,
        ?array $icons = null,
    ): self {
        $this->assertNotBuilt();

        $this->serverInfo = new Implementation(
            name: $name,
            version: $version,
            title: $title,
            description: $description,
            websiteUrl: $websiteUrl,
            icons: $icons,
        );

        return $this;
    }

    /**
     * Caps how many inbound messages the server processes at once. Past the cap a request is
     * answered `-32000` and a notification is dropped, until running handlers finish. Null lifts
     * the cap, which is the default. A `subscriptions/listen` is exempt: it opens a subscription rather
     * than being processed, and the subscription store bounds how many streams may be open.
     */
    public function setMaxInFlightDispatches(?int $max): self
    {
        $this->assertNotBuilt();

        Assert::that($max)->nullOr()->isPositiveInt('Maximum in-flight dispatches must be a positive integer or null, {value} given.');

        $this->maxInFlight = $max;

        return $this;
    }

    /**
     * Controls how much of the server's identity rides the `_meta` of the results it sends.
     */
    public function setServerInfoDisclosure(ServerInfoDisclosure $disclosure): self
    {
        $this->assertNotBuilt();

        $this->serverInfoDisclosure = $disclosure;

        return $this;
    }

    public function setInstructions(?string $instructions): self
    {
        $this->assertNotBuilt();

        Assert::that($instructions)
            ->nullOr()
            ->isNonEmptyString('Server instructions must be a non-empty string or null.')
        ;

        $this->instructions = $instructions;

        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->assertNotBuilt();

        $this->logger = $logger;

        return $this;
    }

    public function setSchemaValidator(SchemaValidatorInterface $validator): self
    {
        $this->assertNotBuilt();

        $this->schemaValidator = $validator;

        return $this;
    }

    /**
     * Sets how many entries one page of a list result carries, for every store the builder assembles
     * from its `add*()` entries. A store supplied through `setToolStore()` and its siblings keeps its own.
     */
    public function setPageSize(int $pageSize): self
    {
        $this->assertNotBuilt();

        Assert::that($pageSize)->isPositiveInt('Store page size must be a positive integer, {value} given.');

        $this->pageSize = $pageSize;

        return $this;
    }

    /**
     * Sets how many milliseconds a client may treat a list result as fresh, for every store the builder
     * assembles from its `add*()` entries. Zero asks the client to re-fetch every time.
     */
    public function setTtlMs(int $ttlMs): self
    {
        $this->assertNotBuilt();

        Assert::that($ttlMs)->isNaturalInt('Store TTL must be a non-negative integer, {value} given.');

        $this->ttlMs = $ttlMs;

        return $this;
    }

    /**
     * Sets which caches may serve a list result, for every store the builder assembles from its
     * `add*()` entries.
     */
    public function setCacheScope(CacheScope $cacheScope): self
    {
        $this->assertNotBuilt();

        $this->cacheScope = $cacheScope;

        return $this;
    }

    /**
     * @param (\Closure(?array<string, mixed>, ServerContext): (CallToolResult|InputRequiredResult))|ToolExecutorInterface $executor
     */
    public function addTool(Tool $tool, \Closure|ToolExecutorInterface $executor): self
    {
        $this->assertNotBuilt();

        $this->tools[$tool->name] = new ToolEntry(
            $tool,
            $executor instanceof ToolExecutorInterface ? $executor : new ClosureToolExecutor($executor),
        );

        return $this;
    }

    /**
     * @param (\Closure(?array<string, string>, ServerContext): (GetPromptResult|InputRequiredResult))|PromptRendererInterface $renderer
     */
    public function addPrompt(Prompt $prompt, \Closure|PromptRendererInterface $renderer): self
    {
        $this->assertNotBuilt();

        $this->prompts[$prompt->name] = new PromptEntry(
            $prompt,
            $renderer instanceof PromptRendererInterface ? $renderer : new ClosurePromptRenderer($renderer),
        );

        return $this;
    }

    /**
     * @param (\Closure(string, ServerContext): (InputRequiredResult|ReadResourceResult))|ResourceReaderInterface $reader
     */
    public function addResource(Resource $resource, \Closure|ResourceReaderInterface $reader): self
    {
        $this->assertNotBuilt();

        $this->resources[$resource->uri] = new ResourceEntry(
            $resource,
            $reader instanceof ResourceReaderInterface ? $reader : new ClosureResourceReader($reader),
        );

        return $this;
    }

    /**
     * @param (\Closure(string, array<string, string>, ServerContext): (InputRequiredResult|ReadResourceResult))|TemplatedResourceReaderInterface $reader
     */
    public function addResourceTemplate(
        ResourceTemplate $template,
        \Closure|TemplatedResourceReaderInterface $reader,
    ): self {
        $this->assertNotBuilt();

        Validator::validate($template->uriTemplate, 'ResourceTemplate');

        $this->resourceTemplates[$template->uriTemplate] = new ResourceTemplateEntry(
            $template,
            $reader instanceof TemplatedResourceReaderInterface ? $reader : new ClosureTemplatedResourceReader($reader),
        );

        return $this;
    }

    public function setToolStore(ToolStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->toolStore = $store;

        return $this;
    }

    /**
     * The tool store the built server serves, or null when it exposes no tools. Assembled from the
     * `addTool()` and `register()` entries unless `setToolStore()` supplied one.
     *
     * Call it once every tool is registered, since it holds the store it returns. Pass the result to
     * `SecuredHttpEndpoint` so `Mcp-Param-{Name}` validation reads the same tools the handlers serve.
     */
    public function getToolStore(): ?ToolStoreInterface
    {
        if (null === $this->toolStore && [] === $this->tools) {
            return null;
        }

        $this->toolStore ??= new ToolStore(
            entries: $this->tools,
            pageSize: $this->pageSize,
            validator: $this->schemaValidator,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
        );

        return $this->toolStore;
    }

    public function setPromptStore(PromptStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->promptStore = $store;

        return $this;
    }

    /**
     * The prompt store the built server serves, or null when it exposes no prompts. Assembled from the
     * `addPrompt()` and `register()` entries unless `setPromptStore()` supplied one.
     *
     * Call it once every prompt is registered, since it holds the store it returns.
     */
    public function getPromptStore(): ?PromptStoreInterface
    {
        if (null === $this->promptStore && [] === $this->prompts) {
            return null;
        }

        $this->promptStore ??= new PromptStore(
            entries: $this->prompts,
            pageSize: $this->pageSize,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
        );

        return $this->promptStore;
    }

    public function setResourceStore(ResourceStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->resourceStore = $store;

        return $this;
    }

    /**
     * The resource store the built server serves, or null when it exposes neither resources nor resource
     * templates. Assembled from the `addResource()` and `register()` entries unless `setResourceStore()`
     * supplied one.
     *
     * Call it once every resource is registered, since it holds the store it returns.
     */
    public function getResourceStore(): ?ResourceStoreInterface
    {
        // A template store alone still needs a resource store, since `resources/read` composes the two.
        $templateStore = $this->getResourceTemplateStore();

        if (null === $this->resourceStore && [] === $this->resources && null === $templateStore) {
            return null;
        }

        $this->resourceStore ??= new ResourceStore(
            entries: $this->resources,
            pageSize: $this->pageSize,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
        );

        return $this->resourceStore;
    }

    public function setResourceTemplateStore(ResourceTemplateStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->resourceTemplateStore = $store;

        return $this;
    }

    /**
     * The resource template store the built server serves, or null when it exposes no templates. Assembled
     * from the `addResourceTemplate()` and `register()` entries unless `setResourceTemplateStore()` supplied
     * one.
     *
     * Call it once every template is registered, since it holds the store it returns.
     */
    public function getResourceTemplateStore(): ?ResourceTemplateStoreInterface
    {
        if (null === $this->resourceTemplateStore && [] === $this->resourceTemplates) {
            return null;
        }

        $this->resourceTemplateStore ??= new ResourceTemplateStore(
            entries: $this->resourceTemplates,
            pageSize: $this->pageSize,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
        );

        return $this->resourceTemplateStore;
    }

    /**
     * Serves `subscriptions/listen` from `$store`, and lights up the `listChanged` capability of every
     * feature whose store can report its changes.
     */
    public function setSubscriptionStore(SubscriptionStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->subscriptionStore = $store;

        return $this;
    }

    public function setCompletionStore(CompletionStoreInterface $store): self
    {
        $this->assertNotBuilt();

        $this->completionStore = $store;

        return $this;
    }

    /**
     * @param (\Closure(string, ?array<string, string>, ServerContext): CompleteResult)|CompletionProviderInterface $provider
     */
    public function addPromptCompletion(string $prompt, string $argument, \Closure|CompletionProviderInterface $provider): self
    {
        $this->assertNotBuilt();

        Assert::that($prompt)->isNonEmptyString('Completion prompt name must be a non-empty string.');
        Assert::that($argument)->isNonEmptyString('Completion argument name must be a non-empty string.');

        $this->promptCompletions[$prompt][$argument] = $provider instanceof CompletionProviderInterface
            ? $provider
            : new ClosureCompletionProvider($provider);

        return $this;
    }

    /**
     * @param (\Closure(string, ?array<string, string>, ServerContext): CompleteResult)|CompletionProviderInterface $provider
     */
    public function addResourceTemplateCompletion(string $uriTemplate, string $argument, \Closure|CompletionProviderInterface $provider): self
    {
        $this->assertNotBuilt();

        Assert::that($uriTemplate)->isNonEmptyString('Completion URI template must be a non-empty string.');
        Assert::that($argument)->isNonEmptyString('Completion argument name must be a non-empty string.');

        $this->templateCompletions[$uriTemplate][$argument] = $provider instanceof CompletionProviderInterface
            ? $provider
            : new ClosureCompletionProvider($provider);

        return $this;
    }

    /**
     * The completion store the built server serves, or null when it serves no completions. Assembled
     * from the `addPromptCompletion()`, `addResourceTemplateCompletion()`, and `register()` entries
     * unless `setCompletionStore()` supplied one.
     *
     * Call it once every completion is registered, since it holds the store it returns.
     */
    public function getCompletionStore(): ?CompletionStoreInterface
    {
        if (null === $this->completionStore && [] === $this->promptCompletions && [] === $this->templateCompletions) {
            return null;
        }

        $this->completionStore ??= new CompletionStore($this->promptCompletions, $this->templateCompletions);

        return $this->completionStore;
    }

    /**
     * Registers the server identity (`#[AsServer]`) plus the tools, prompts, resources,
     * resource templates, and completion providers discovered from `#[AsTool]`, `#[AsPrompt]`,
     * `#[AsResource]`, `#[AsResourceTemplate]`, and `#[AsCompletion]` methods on each source
     * object. An explicit `setServerInfo()` or `setInstructions()` call takes precedence over
     * the matching `#[AsServer]` field, and at most one registered source may declare
     * `#[AsServer]`.
     *
     * @throws DuplicateServerMetadataException
     * @throws MissingDiscoveryAttributeException
     */
    public function register(object ...$sources): self
    {
        $this->assertNotBuilt();

        $scanner = new AttributeScanner();

        foreach ($sources as $source) {
            $contributed = false;
            $metadata = self::findServerMetadata($source);

            if (null !== $metadata) {
                if (null !== $this->serverMetadata) {
                    throw new DuplicateServerMetadataException($source::class);
                }

                $this->serverMetadata = $metadata;
                $contributed = true;
            }

            foreach ($scanner->scan($source) as $entry) {
                $contributed = true;

                match (true) {
                    $entry instanceof ToolEntry => $this->addTool($entry->tool, $entry->executor),
                    $entry instanceof PromptEntry => $this->addPrompt($entry->prompt, $entry->renderer),
                    $entry instanceof ResourceEntry => $this->addResource($entry->resource, $entry->reader),
                    $entry instanceof ResourceTemplateEntry => $this->addResourceTemplate($entry->template, $entry->reader),
                    $entry instanceof PromptCompletionEntry => $this->addPromptCompletion($entry->prompt, $entry->argument, $entry->provider),
                    default => $this->addResourceTemplateCompletion($entry->uriTemplate, $entry->argument, $entry->provider),
                };
            }

            if (! $contributed) {
                throw new MissingDiscoveryAttributeException($source::class);
            }
        }

        return $this;
    }

    /**
     * Enables `$extension`, advertising its capability identifier and serving its methods
     * behind the per-request declared-capability gate.
     *
     * @throws DuplicateExtensionException
     * @throws ExtensionMethodCollisionException
     */
    public function enableExtension(ServerExtensionInterface $extension): self
    {
        $this->assertNotBuilt();

        $this->extensions->add(
            $extension,
            claimedRequests: array_keys($this->customRequestHandlers),
            claimedNotifications: array_keys($this->customNotificationHandlers),
            requireClientRequests: true,
            requestDecorators: $extension instanceof RequestHandlerDecoratorInterface
                ? $extension->getRequestHandlerDecorators()
                : [],
        );

        return $this;
    }

    /**
     * Registers a handler for a vendor-extension request method.
     *
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     * @param class-string<JsonRpcRequest<non-empty-string>>                   $requestClass Parses inbound `$method` envelopes into typed requests
     *
     * @throws ExtensionMethodCollisionException
     * @throws ReservedMethodException
     *
     * @see self::replaceRequestHandler()
     */
    public function addRequestHandler(string $method, RequestHandlerInterface $handler, string $requestClass): self
    {
        $this->assertNotBuilt();

        if (\array_key_exists($method, JsonRpcMethodRegistry::requests())) {
            throw new ReservedMethodException($method);
        }

        $this->extensions->assertNotOwned($method);

        MethodClassValidator::validate($requestClass, $method);

        Assert::that($requestClass)->isSubclassOf(ClientRequest::class, \sprintf(
            'Request class "%s" must implement "%s" for the server to dispatch it.',
            $requestClass,
            ClientRequest::class,
        ));

        $this->customRequestHandlers[$method] = $handler;
        $this->customRequestClasses[$method] = $requestClass;

        return $this;
    }

    /**
     * Overrides the SDK's built-in handler for `$method`.
     *
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     *
     * @throws UnreservedMethodException
     *
     * @see self::addRequestHandler()
     */
    public function replaceRequestHandler(string $method, RequestHandlerInterface $handler): self
    {
        $this->assertNotBuilt();

        if (! \array_key_exists($method, JsonRpcMethodRegistry::requests())) {
            throw new UnreservedMethodException($method);
        }

        $this->customRequestHandlers[$method] = $handler;

        return $this;
    }

    /**
     * Registers a handler for a vendor-extension notification method.
     *
     * @param non-empty-string                                    $method
     * @param NotificationHandlerInterface<non-empty-string>      $handler
     * @param class-string<JsonRpcNotification<non-empty-string>> $notificationClass Parses inbound `$method` envelopes into typed notifications
     *
     * @throws ExtensionMethodCollisionException
     * @throws ReservedMethodException
     *
     * @see self::replaceNotificationHandler()
     */
    public function addNotificationHandler(string $method, NotificationHandlerInterface $handler, string $notificationClass): self
    {
        $this->assertNotBuilt();

        if (\array_key_exists($method, JsonRpcMethodRegistry::notifications())) {
            throw new ReservedMethodException($method, isNotification: true);
        }

        $this->extensions->assertNotOwned($method, isNotification: true);

        MethodClassValidator::validate($notificationClass, $method, isNotification: true);

        $this->customNotificationHandlers[$method] = $handler;
        $this->customNotificationClasses[$method] = $notificationClass;

        return $this;
    }

    /**
     * Overrides any built-in handler for `$method`, including spec notifications.
     *
     * @param non-empty-string                               $method
     * @param NotificationHandlerInterface<non-empty-string> $handler
     *
     * @throws UnreservedMethodException
     *
     * @see self::addNotificationHandler()
     */
    public function replaceNotificationHandler(string $method, NotificationHandlerInterface $handler): self
    {
        $this->assertNotBuilt();

        if (! \array_key_exists($method, JsonRpcMethodRegistry::notifications())) {
            throw new UnreservedMethodException($method, isNotification: true);
        }

        $this->customNotificationHandlers[$method] = $handler;

        return $this;
    }

    public function build(): Server
    {
        $this->assertNotBuilt();
        $this->built = true;
        $serverInfo = $this->resolveServerInfo();

        Assert::that($serverInfo)->isInstanceOf(
            Implementation::class,
            'Server information must be set before build() via setServerInfo() or a class-level #[AsServer].',
        );

        $capabilities = $this->deriveCapabilities();

        $requestHandlers = $this->buildRequestHandlers(
            $capabilities,
            ServerInfoDisclosure::None === $this->serverInfoDisclosure ? null : $serverInfo,
        );

        $this->routeListChanges();

        $inboundRequests = new PendingInboundRequests();

        return new Server(
            new ServerMessageDispatcher(
                new HandlerRegistry($requestHandlers, RequestHandlerInterface::class, 'Request handler'),
                new HandlerRegistry(
                    $this->buildNotificationHandlers($inboundRequests),
                    NotificationHandlerInterface::class,
                    'Notification handler',
                ),
                logger: $this->logger,
                parser: new JsonRpcMessageParser(
                    [...$this->extensions->buildRequestClasses(), ...$this->customRequestClasses],
                    [...$this->extensions->buildNotificationClasses(), ...$this->customNotificationClasses],
                ),
                serverInfo: $this->serverInfoDisclosure->project($serverInfo),
                maxInFlight: $this->maxInFlight,
                inboundRequests: $inboundRequests,
            ),
            $this->logger,
            $this->subscriptionStore,
        );
    }

    /**
     * Turns each store's change signal into the notification its own feature owns.
     */
    private function routeListChanges(): void
    {
        $subscriptionStore = $this->subscriptionStore;

        if (null === $subscriptionStore) {
            return;
        }

        $toolStore = $this->getToolStore();

        if ($toolStore instanceof ListChangeSourceInterface) {
            $toolStore->onListChanged($subscriptionStore->emitToolListChanged(...));
        }

        $promptStore = $this->getPromptStore();

        if ($promptStore instanceof ListChangeSourceInterface) {
            $promptStore->onListChanged($subscriptionStore->emitPromptListChanged(...));
        }

        $resourceStore = $this->getResourceStore();

        if ($resourceStore instanceof ListChangeSourceInterface) {
            $resourceStore->onListChanged($subscriptionStore->emitResourceListChanged(...));
        }

        $templateStore = $this->getResourceTemplateStore();

        if ($templateStore instanceof ListChangeSourceInterface) {
            $templateStore->onListChanged($subscriptionStore->emitResourceListChanged(...));
        }
    }

    /**
     * @return array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    private function buildNotificationHandlers(PendingInboundRequests $inboundRequests): array
    {
        $defaults = [
            CancelledNotification::getMethod() => new CancelledNotificationHandler($inboundRequests, $this->logger),
        ];

        return [...$defaults, ...$this->extensions->buildNotificationHandlers(), ...$this->customNotificationHandlers];
    }

    /**
     * Merges the explicit `setServerInfo()` values over the `#[AsServer]` fields, with the
     * attribute filling only the gaps the setter left null.
     */
    private function resolveServerInfo(): ?Implementation
    {
        $metadata = $this->serverMetadata;

        if (null === $metadata) {
            return $this->serverInfo;
        }

        if (null === $this->serverInfo) {
            return new Implementation(
                name: $metadata->name,
                version: $metadata->version,
                title: $metadata->title,
                description: $metadata->description,
                websiteUrl: $metadata->websiteUrl,
                icons: $metadata->icons,
            );
        }

        return new Implementation(
            name: $this->serverInfo->name,
            version: $this->serverInfo->version,
            title: $this->serverInfo->title ?? $metadata->title,
            description: $this->serverInfo->description ?? $metadata->description,
            websiteUrl: $this->serverInfo->websiteUrl ?? $metadata->websiteUrl,
            icons: $this->serverInfo->icons ?? $metadata->icons,
        );
    }

    /**
     * @return null|non-empty-string
     */
    private function resolveInstructions(): ?string
    {
        $instructions = $this->instructions ?? $this->serverMetadata?->instructions;

        Assert::that($instructions)
            ->nullOr()
            ->isNonEmptyString('Server instructions must be a non-empty string or null.')
        ;

        return $instructions;
    }

    private static function findServerMetadata(object $source): ?AsServer
    {
        $attributes = new \ReflectionObject($source)->getAttributes(AsServer::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * @throws BuilderAlreadyBuiltException
     */
    private function assertNotBuilt(): void
    {
        if ($this->built) {
            // The built server holds the stores and the list-change listeners, so a later registration would
            // be dropped without a trace.
            throw new BuilderAlreadyBuiltException();
        }
    }

    private function deriveCapabilities(): ServerCapabilities
    {
        $honoured = $this->resolveHonouredNotifications();

        return new ServerCapabilities(
            completions: $this->hasCompletionsCapability() ? [] : null,
            extensions: $this->extensions->buildCapabilitySlot(),
            prompts: $this->hasPromptsCapability()
                ? self::listChangedFlag($this->getPromptStore() instanceof ListChangeSourceInterface, $honoured?->promptsListChanged)
                : null,
            resources: $this->resourcesCapability($honoured),
            tools: $this->hasToolsCapability()
                ? self::listChangedFlag($this->getToolStore() instanceof ListChangeSourceInterface, $honoured?->toolsListChanged)
                : null,
        );
    }

    /**
     * What the registered subscription store will deliver, or null when none is registered. Asking for
     * everything makes the answer the store's own declaration, so no capability can promise more.
     */
    private function resolveHonouredNotifications(): ?SubscriptionFilter
    {
        return $this->subscriptionStore?->honour(new SubscriptionFilter(
            toolsListChanged: true,
            promptsListChanged: true,
            resourcesListChanged: true,
            resourceSubscriptions: [],
        ));
    }

    /**
     * `listChanged` is a promise to deliver, so it is only set when the store behind the feature can report
     * a change and the subscription store honours that notification type.
     *
     * @return array{listChanged?: bool}
     */
    private static function listChangedFlag(bool $reportsChanges, ?bool $honoured): array
    {
        if (true !== $honoured || ! $reportsChanges) {
            return [];
        }

        return ['listChanged' => true];
    }

    /**
     * @return null|array{listChanged?: bool, subscribe?: bool}
     */
    private function resourcesCapability(?SubscriptionFilter $honoured): ?array
    {
        if (! $this->hasResourcesCapability()) {
            return null;
        }

        // Both stores fan into `notifications/resources/list_changed`, so either one reporting is a promise
        // the server can keep.
        $reportsChanges = $this->getResourceStore() instanceof ListChangeSourceInterface
            || $this->getResourceTemplateStore() instanceof ListChangeSourceInterface;

        $capability = self::listChangedFlag($reportsChanges, $honoured?->resourcesListChanged);

        if (null !== $honoured?->resourceSubscriptions) {
            // `subscribe` means the server honours `resourceSubscriptions` on a listen filter.
            $capability['subscribe'] = true;
        }

        return $capability;
    }

    private function hasCompletionsCapability(): bool
    {
        return $this->getCompletionStore() !== null
            || isset($this->customRequestHandlers[CompleteRequest::getMethod()]);
    }

    private function hasPromptsCapability(): bool
    {
        $store = $this->getPromptStore();

        if (null !== $store) {
            return true;
        }

        return isset($this->customRequestHandlers[GetPromptRequest::getMethod()])
            && isset($this->customRequestHandlers[ListPromptsRequest::getMethod()]);
    }

    private function hasResourcesCapability(): bool
    {
        $store = $this->getResourceStore();

        if (null !== $store) {
            return true;
        }

        return isset($this->customRequestHandlers[ListResourcesRequest::getMethod()])
            && isset($this->customRequestHandlers[ReadResourceRequest::getMethod()]);
    }

    private function hasToolsCapability(): bool
    {
        $store = $this->getToolStore();

        if (null !== $store) {
            return true;
        }

        return isset($this->customRequestHandlers[CallToolRequest::getMethod()])
            && isset($this->customRequestHandlers[ListToolsRequest::getMethod()]);
    }

    /**
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private function buildRequestHandlers(ServerCapabilities $capabilities, ?Implementation $serverInfo): array
    {
        $defaults = [
            DiscoverRequest::getMethod() => new DiscoverRequestHandler(
                $capabilities,
                $this->resolveInstructions(),
                serverInfo: $serverInfo,
            ),
        ];

        $toolStore = $this->getToolStore();

        if (null !== $toolStore) {
            $defaults[ListToolsRequest::getMethod()] = new ListToolsRequestHandler($toolStore);
            $defaults[CallToolRequest::getMethod()] = new CallToolRequestHandler($toolStore, $this->logger);
        }

        $promptStore = $this->getPromptStore();

        if (null !== $promptStore) {
            $defaults[ListPromptsRequest::getMethod()] = new ListPromptsRequestHandler($promptStore);
            $defaults[GetPromptRequest::getMethod()] = new GetPromptRequestHandler($promptStore);
        }

        $resourceTemplateStore = $this->getResourceTemplateStore();

        if (null !== $resourceTemplateStore) {
            $defaults[ListResourceTemplatesRequest::getMethod()] = new ListResourceTemplatesRequestHandler($resourceTemplateStore);
        }

        $resourceStore = $this->getResourceStore();

        if (null !== $resourceStore) {
            $defaults[ListResourcesRequest::getMethod()] = new ListResourcesRequestHandler($resourceStore);
            $defaults[ReadResourceRequest::getMethod()] = new ReadResourceRequestHandler(
                null !== $resourceTemplateStore ? new CompositeResourceStore($resourceStore, $resourceTemplateStore) : $resourceStore,
            );
        }

        $completionStore = $this->getCompletionStore();

        if (null !== $completionStore) {
            $defaults[CompleteRequest::getMethod()] = new CompleteRequestHandler($completionStore);
        }

        if (null !== $this->subscriptionStore) {
            $defaults[SubscriptionsListenRequest::getMethod()] = new SubscriptionsListenRequestHandler($this->subscriptionStore);
        }

        return $this->applyRequestDecorators([...$defaults, ...$this->buildExtensionRequestHandlers(), ...$this->customRequestHandlers]);
    }

    /**
     * Wraps each enabled extension's request handlers in the declared-capability gate.
     *
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private function buildExtensionRequestHandlers(): array
    {
        $handlers = [];

        foreach ($this->extensions->getRequestHandlerGroups() as $identifier => $group) {
            foreach ($group as $method => $handler) {
                $handlers[$method] = new ExtensionGateRequestHandler($identifier, $handler);
            }
        }

        return $handlers;
    }

    /**
     * Wraps decorated methods' effective handlers, replacement or default, with
     * each enabled extension's decorators in enable order.
     *
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>> $handlers
     *
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private function applyRequestDecorators(array $handlers): array
    {
        /** @var array<non-empty-string, array<non-empty-string, RequestHandlerDecorator>> $groups */
        $groups = $this->extensions->getRequestDecoratorGroups();

        foreach ($groups as $identifier => $decorators) {
            foreach ($decorators as $method => $decorate) {
                Assert::that($handlers)->hasOffset($method, \sprintf(
                    'Extension "%s" decorates "%s", but no handler serves that method.',
                    $identifier,
                    $method,
                ));

                $decorated = $decorate($handlers[$method]);
                Assert::that($decorated)->isInstanceOf(RequestHandlerInterface::class, \sprintf(
                    'Extension "%s" decorator for "%s" must return a request handler, {type} given.',
                    $identifier,
                    $method,
                ));

                $handlers[$method] = $decorated;
            }
        }

        return $handlers;
    }
}
