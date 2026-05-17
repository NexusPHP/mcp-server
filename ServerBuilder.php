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
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\NotificationHandlerRegistry;
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerRegistry;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\Notification;
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
use Nexus\Mcp\Server\Completion\CompletionStoreInterface;
use Nexus\Mcp\Server\Dispatch\InitializationGate;
use Nexus\Mcp\Server\Dispatch\MessageDispatcher;
use Nexus\Mcp\Server\Handler\Request\CallToolRequestHandler;
use Nexus\Mcp\Server\Handler\Request\CompleteRequestHandler;
use Nexus\Mcp\Server\Handler\Request\GetPromptRequestHandler;
use Nexus\Mcp\Server\Handler\Request\InitializeRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListPromptsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourcesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListResourceTemplatesRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ListToolsRequestHandler;
use Nexus\Mcp\Server\Handler\Request\ReadResourceRequestHandler;
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

    private LoggerInterface $logger;

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
     * Registers a handler for a vendor-extension request method. Rejects
     * methods reserved for the SDK's built-in handlers. Use
     * `replaceRequestHandler()` to override those explicitly.
     *
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     *
     * @throws \LogicException
     */
    public function addRequestHandler(string $method, RequestHandlerInterface $handler): self
    {
        if (\in_array($method, self::specReservedRequestMethods(), true)) {
            throw new \LogicException(\sprintf(
                'Request method "%s" is reserved for the SDK\'s built-in handler. Use replaceRequestHandler() to override it.',
                $method,
            ));
        }

        $this->customRequestHandlers[$method] = $handler;

        return $this;
    }

    /**
     * Overrides the SDK's built-in handler for `$method` (including spec
     * methods like `initialize`). Use this when you genuinely want to replace
     * SDK behaviour. Use `addRequestHandler()` for vendor extensions.
     *
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     */
    public function replaceRequestHandler(string $method, RequestHandlerInterface $handler): self
    {
        $this->customRequestHandlers[$method] = $handler;

        return $this;
    }

    /**
     * Registers a handler for a vendor-extension notification method. Rejects
     * methods reserved by the MCP spec. Use `replaceNotificationHandler()` to
     * attach a handler to a spec method explicitly.
     *
     * @param non-empty-string                               $method
     * @param NotificationHandlerInterface<non-empty-string> $handler
     *
     * @throws \LogicException
     */
    public function addNotificationHandler(string $method, NotificationHandlerInterface $handler): self
    {
        if (\in_array($method, self::specReservedNotificationMethods(), true)) {
            throw new \LogicException(\sprintf(
                'Notification method "%s" is reserved by the MCP spec. Use replaceNotificationHandler() to attach a handler to it.',
                $method,
            ));
        }

        $this->customNotificationHandlers[$method] = $handler;

        return $this;
    }

    /**
     * Overrides any built-in handler for `$method` (including spec
     * notifications). Use this when attaching to a spec notification.
     * Use `addNotificationHandler()` for vendor extensions.
     *
     * @param non-empty-string                               $method
     * @param NotificationHandlerInterface<non-empty-string> $handler
     */
    public function replaceNotificationHandler(string $method, NotificationHandlerInterface $handler): self
    {
        $this->customNotificationHandlers[$method] = $handler;

        return $this;
    }

    public function build(): Server
    {
        Assert::that($this->serverInfo)
            ->isInstanceOf(Implementation::class, 'Server info must be set before build() via setServerInfo().')
        ;

        $capabilities = $this->deriveCapabilities();

        $requestHandlers = $this->buildRequestHandlers($this->serverInfo, $capabilities);

        return new Server(
            new MessageDispatcher(
                new RequestHandlerRegistry($requestHandlers),
                new NotificationHandlerRegistry($this->customNotificationHandlers),
                new InitializationGate(),
                $this->logger,
            ),
            $this->logger,
        );
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
    private function buildRequestHandlers(Implementation $serverInfo, ServerCapabilities $capabilities): array
    {
        $defaults = [
            Request\InitializeRequest::method() => new InitializeRequestHandler($serverInfo, $capabilities, $this->instructions),
            Request\PingRequest::method() => new PingRequestHandler(),
        ];

        if ([] !== $this->tools) {
            $toolStore = new ToolStore($this->tools);
            $defaults[Request\ListToolsRequest::method()] = new ListToolsRequestHandler($toolStore);
            $defaults[Request\CallToolRequest::method()] = new CallToolRequestHandler($toolStore);
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

    /**
     * @return list<non-empty-string>
     */
    private static function specReservedRequestMethods(): array
    {
        return [
            Request\CallToolRequest::method(),
            Request\CompleteRequest::method(),
            Request\GetPromptRequest::method(),
            Request\InitializeRequest::method(),
            Request\ListPromptsRequest::method(),
            Request\ListResourcesRequest::method(),
            Request\ListResourceTemplatesRequest::method(),
            Request\ListToolsRequest::method(),
            Request\PingRequest::method(),
            Request\ReadResourceRequest::method(),
        ];
    }

    /**
     * @return list<non-empty-string>
     */
    private static function specReservedNotificationMethods(): array
    {
        return [
            Notification\CancelledNotification::method(),
            Notification\ElicitationCompleteNotification::method(),
            Notification\InitializedNotification::method(),
            Notification\LoggingMessageNotification::method(),
            Notification\ProgressNotification::method(),
            Notification\PromptListChangedNotification::method(),
            Notification\ResourceListChangedNotification::method(),
            Notification\ResourceUpdatedNotification::method(),
            Notification\RootsListChangedNotification::method(),
            Notification\TaskStatusNotification::method(),
            Notification\ToolListChangedNotification::method(),
        ];
    }
}
