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
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMethodRegistry;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Completion\CompletionStoreInterface;
use Nexus\Mcp\Server\Discovery\AttributeScanner;
use Nexus\Mcp\Server\Dispatch\ServerInitializationGate;
use Nexus\Mcp\Server\Dispatch\ServerMessageDispatcher;
use Nexus\Mcp\Server\Exception\DuplicateServerMetadataException;
use Nexus\Mcp\Server\Exception\ReservedMethodException;
use Nexus\Mcp\Server\Exception\UnreservedMethodException;
use Nexus\Mcp\Server\Handler\Request\CallToolRequestHandler;
use Nexus\Mcp\Server\Handler\Request\CompleteRequestHandler;
use Nexus\Mcp\Server\Handler\Request\GetPromptRequestHandler;
use Nexus\Mcp\Server\Handler\Request\InitializeRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListPromptsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourcesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourceTemplatesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListToolsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ReadResourceRequestHandler;
use Nexus\Mcp\Server\Handler\Request\SetLevelRequestHandler;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\PromptRendererInterface;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\CompositeResourceStore;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceReaderInterface;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\Resource\TemplatedResourceReaderInterface;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolExecutorInterface;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder that wires the per-feature stores, the dispatch kernel, and
 * the lifecycle shell into a runnable `Server` instance.
 */
final class ServerBuilder
{
    private ?Implementation $serverInfo = null;

    /**
     * @var null|non-empty-string
     */
    private ?string $instructions = null;

    private ?AsServer $serverMetadata = null;
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

    private ?CompletionStoreInterface $completionStore = null;

    /**
     * @var array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private array $customRequestHandlers = [];

    /**
     * @var array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    private array $customNotificationHandlers = [];

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->schemaValidator = new OpisSchemaValidator();
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
        $this->serverInfo = new Implementation($name, $version, $title, $description, $websiteUrl, $icons);

        return $this;
    }

    public function setInstructions(?string $instructions): self
    {
        Assert::that($instructions)
            ->nullOr()
            ->isNonEmptyString('Server instructions must be a non-empty string or null.')
        ;

        $this->instructions = $instructions;

        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function setSchemaValidator(SchemaValidatorInterface $validator): self
    {
        $this->schemaValidator = $validator;

        return $this;
    }

    /**
     * @param (\Closure(?array<string, mixed>, ServerContext): CallToolResult)|ToolExecutorInterface $executor
     */
    public function addTool(Tool $tool, \Closure|ToolExecutorInterface $executor): self
    {
        $this->tools[$tool->name] = new ToolEntry(
            $tool,
            $executor instanceof ToolExecutorInterface ? $executor : new ClosureToolExecutor($executor),
        );

        return $this;
    }

    /**
     * @param (\Closure(?array<string, string>, ServerContext): GetPromptResult)|PromptRendererInterface $renderer
     */
    public function addPrompt(Prompt $prompt, \Closure|PromptRendererInterface $renderer): self
    {
        $this->prompts[$prompt->name] = new PromptEntry(
            $prompt,
            $renderer instanceof PromptRendererInterface ? $renderer : new ClosurePromptRenderer($renderer),
        );

        return $this;
    }

    /**
     * @param (\Closure(string, ServerContext): ReadResourceResult)|ResourceReaderInterface $reader
     */
    public function addResource(Resource $resource, \Closure|ResourceReaderInterface $reader): self
    {
        $this->resources[$resource->uri] = new ResourceEntry(
            $resource,
            $reader instanceof ResourceReaderInterface ? $reader : new ClosureResourceReader($reader),
        );

        return $this;
    }

    /**
     * @param (\Closure(string, array<string, string>, ServerContext): ReadResourceResult)|TemplatedResourceReaderInterface $reader
     */
    public function addResourceTemplate(
        ResourceTemplate $template,
        \Closure|TemplatedResourceReaderInterface $reader,
    ): self {
        Validator::validate($template->uriTemplate, 'ResourceTemplate');

        $this->resourceTemplates[$template->uriTemplate] = new ResourceTemplateEntry(
            $template,
            $reader instanceof TemplatedResourceReaderInterface ? $reader : new ClosureTemplatedResourceReader($reader),
        );

        return $this;
    }

    public function setCompletionStore(CompletionStoreInterface $store): self
    {
        $this->completionStore = $store;

        return $this;
    }

    /**
     * Registers the server identity (`#[AsServer]`) plus the tools, prompts, resources, and
     * resource templates discovered from `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, and
     * `#[AsResourceTemplate]` methods on each source object. An explicit `setServerInfo()` or
     * `setInstructions()` call takes precedence over the matching `#[AsServer]` field, and at
     * most one registered source may declare `#[AsServer]`.
     *
     * @throws DuplicateServerMetadataException
     */
    public function register(object ...$sources): self
    {
        $scanner = new AttributeScanner();

        foreach ($sources as $source) {
            $metadata = self::serverMetadataOf($source);

            if (null !== $metadata) {
                if (null !== $this->serverMetadata) {
                    throw new DuplicateServerMetadataException($source::class);
                }

                $this->serverMetadata = $metadata;
            }

            foreach ($scanner->scan($source) as $entry) {
                if ($entry instanceof ToolEntry) {
                    $this->addTool($entry->tool, $entry->executor);
                } elseif ($entry instanceof PromptEntry) {
                    $this->addPrompt($entry->prompt, $entry->renderer);
                } elseif ($entry instanceof ResourceEntry) {
                    $this->addResource($entry->resource, $entry->reader);
                } else {
                    $this->addResourceTemplate($entry->template, $entry->reader);
                }
            }
        }

        return $this;
    }

    /**
     * Registers a handler for a vendor-extension request method.
     *
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     *
     * @throws ReservedMethodException
     *
     * @see self::replaceRequestHandler()
     */
    public function addRequestHandler(string $method, RequestHandlerInterface $handler): self
    {
        if (\array_key_exists($method, JsonRpcMethodRegistry::requests())) {
            throw new ReservedMethodException($method);
        }

        $this->customRequestHandlers[$method] = $handler;

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
        if (! \array_key_exists($method, JsonRpcMethodRegistry::requests())) {
            throw new UnreservedMethodException($method);
        }

        $this->customRequestHandlers[$method] = $handler;

        return $this;
    }

    /**
     * Registers a handler for a vendor-extension notification method.
     *
     * @param non-empty-string                               $method
     * @param NotificationHandlerInterface<non-empty-string> $handler
     *
     * @throws ReservedMethodException
     *
     * @see self::replaceNotificationHandler()
     */
    public function addNotificationHandler(string $method, NotificationHandlerInterface $handler): self
    {
        if (\array_key_exists($method, JsonRpcMethodRegistry::notifications())) {
            throw new ReservedMethodException($method, isNotification: true);
        }

        $this->customNotificationHandlers[$method] = $handler;

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
        if (! \array_key_exists($method, JsonRpcMethodRegistry::notifications())) {
            throw new UnreservedMethodException($method, isNotification: true);
        }

        $this->customNotificationHandlers[$method] = $handler;

        return $this;
    }

    public function build(): Server
    {
        $serverInfo = $this->resolveServerInfo();

        Assert::that($serverInfo)->isInstanceOf(
            Implementation::class,
            'Server information must be set before build() via setServerInfo() or a class-level #[AsServer].',
        );

        $capabilities = $this->deriveCapabilities();
        $loggingLevelGate = new LoggingLevelGate();

        $requestHandlers = $this->buildRequestHandlers($serverInfo, $capabilities, $loggingLevelGate);

        return new Server(
            new ServerMessageDispatcher(
                new HandlerRegistry($requestHandlers, RequestHandlerInterface::class, 'Request handler'),
                new HandlerRegistry($this->customNotificationHandlers, NotificationHandlerInterface::class, 'Notification handler'),
                new ServerInitializationGate(),
                loggingLevelGate: $loggingLevelGate,
                logger: $this->logger,
            ),
            $this->logger,
        );
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
                $metadata->name,
                $metadata->version,
                $metadata->title,
                $metadata->description,
                $metadata->websiteUrl,
                $metadata->icons,
            );
        }

        return new Implementation(
            $this->serverInfo->name,
            $this->serverInfo->version,
            $this->serverInfo->title ?? $metadata->title,
            $this->serverInfo->description ?? $metadata->description,
            $this->serverInfo->websiteUrl ?? $metadata->websiteUrl,
            $this->serverInfo->icons ?? $metadata->icons,
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

    private static function serverMetadataOf(object $source): ?AsServer
    {
        $attributes = new \ReflectionObject($source)->getAttributes(AsServer::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    private function deriveCapabilities(): ServerCapabilities
    {
        return new ServerCapabilities(
            completions: $this->hasCompletionsCapability() ? [] : null,
            logging: [],
            prompts: $this->hasPromptsCapability() ? [] : null,
            resources: $this->hasResourcesCapability() ? [] : null,
            tools: $this->hasToolsCapability() ? [] : null,
        );
    }

    private function hasCompletionsCapability(): bool
    {
        return null !== $this->completionStore
            || isset($this->customRequestHandlers[Request\CompleteRequest::method()]);
    }

    private function hasPromptsCapability(): bool
    {
        if ([] !== $this->prompts) {
            return true;
        }

        return isset($this->customRequestHandlers[Request\GetPromptRequest::method()])
            && isset($this->customRequestHandlers[Request\ListPromptsRequest::method()]);
    }

    private function hasResourcesCapability(): bool
    {
        if ([] !== $this->resources || [] !== $this->resourceTemplates) {
            return true;
        }

        return isset($this->customRequestHandlers[Request\ListResourcesRequest::method()])
            && isset($this->customRequestHandlers[Request\ReadResourceRequest::method()]);
    }

    private function hasToolsCapability(): bool
    {
        if ([] !== $this->tools) {
            return true;
        }

        return isset($this->customRequestHandlers[Request\CallToolRequest::method()])
            && isset($this->customRequestHandlers[Request\ListToolsRequest::method()]);
    }

    /**
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private function buildRequestHandlers(
        Implementation $serverInfo,
        ServerCapabilities $capabilities,
        LoggingLevelGate $loggingLevelGate,
    ): array {
        $defaults = [
            Request\InitializeRequest::method() => new InitializeRequestHandler($serverInfo, $capabilities, $this->resolveInstructions()),
            Request\PingRequest::method() => new PingRequestHandler(),
            Request\SetLevelRequest::method() => new SetLevelRequestHandler($loggingLevelGate),
        ];

        if ([] !== $this->tools) {
            $toolStore = new ToolStore($this->tools, validator: $this->schemaValidator);
            $defaults[Request\ListToolsRequest::method()] = new ListToolsRequestHandler($toolStore);
            $defaults[Request\CallToolRequest::method()] = new CallToolRequestHandler($toolStore, $this->logger);
        }

        if ([] !== $this->prompts) {
            $promptStore = new PromptStore($this->prompts);
            $defaults[Request\ListPromptsRequest::method()] = new ListPromptsRequestHandler($promptStore);
            $defaults[Request\GetPromptRequest::method()] = new GetPromptRequestHandler($promptStore);
        }

        if ([] !== $this->resources || [] !== $this->resourceTemplates) {
            $resourceStore = new ResourceStore($this->resources);
            $templateStore = [] !== $this->resourceTemplates ? new ResourceTemplateStore($this->resourceTemplates) : null;

            $defaults[Request\ListResourcesRequest::method()] = new ListResourcesRequestHandler($resourceStore);
            $defaults[Request\ReadResourceRequest::method()] = new ReadResourceRequestHandler(
                null !== $templateStore ? new CompositeResourceStore($resourceStore, $templateStore) : $resourceStore,
            );

            if (null !== $templateStore) {
                $defaults[Request\ListResourceTemplatesRequest::method()] = new ListResourceTemplatesRequestHandler($templateStore);
            }
        }

        if (null !== $this->completionStore) {
            $defaults[Request\CompleteRequest::method()] = new CompleteRequestHandler($this->completionStore);
        }

        return [...$defaults, ...$this->customRequestHandlers];
    }
}
