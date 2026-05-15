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
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Schema\Tool\Tool;
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
use Nexus\Mcp\Server\Prompt\PromptRendererInterface;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ResourceReaderInterface;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
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
     * @var array<non-empty-string, array{tool: Tool, executor: ToolExecutorInterface}>
     */
    private array $tools = [];

    /**
     * @var array<non-empty-string, array{prompt: Prompt, renderer: PromptRendererInterface}>
     */
    private array $prompts = [];

    /**
     * @var array<non-empty-string, array{resource: Resource, reader: ResourceReaderInterface}>
     */
    private array $resources = [];

    /**
     * @var array<non-empty-string, ResourceTemplate>
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
        $this->tools[$tool->name] = [
            'tool' => $tool,
            'executor' => $executor instanceof ToolExecutorInterface
                ? $executor
                : new ClosureToolExecutor($executor),
        ];

        return $this;
    }

    /**
     * @param (\Closure(?array<string, string>, ServerContext): GetPromptResult)|PromptRendererInterface $renderer
     */
    public function addPrompt(Prompt $prompt, \Closure|PromptRendererInterface $renderer): self
    {
        $this->prompts[$prompt->name] = [
            'prompt' => $prompt,
            'renderer' => $renderer instanceof PromptRendererInterface
                ? $renderer
                : new ClosurePromptRenderer($renderer),
        ];

        return $this;
    }

    /**
     * @param (\Closure(string, ServerContext): ReadResourceResult)|ResourceReaderInterface $reader
     */
    public function addResource(Resource $resource, \Closure|ResourceReaderInterface $reader): self
    {
        $this->resources[$resource->uri] = [
            'resource' => $resource,
            'reader' => $reader instanceof ResourceReaderInterface
                ? $reader
                : new ClosureResourceReader($reader),
        ];

        return $this;
    }

    public function addResourceTemplate(ResourceTemplate $template): self
    {
        $this->resourceTemplates[$template->uriTemplate] = $template;

        return $this;
    }

    public function setCompletionStore(CompletionStoreInterface $store): self
    {
        $this->completionStore = $store;

        return $this;
    }

    /**
     * @param non-empty-string                                                 $method
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $handler
     */
    public function addRequestHandler(string $method, RequestHandlerInterface $handler): self
    {
        $this->customRequestHandlers[$method] = $handler;

        return $this;
    }

    /**
     * @param non-empty-string                               $method
     * @param NotificationHandlerInterface<non-empty-string> $handler
     */
    public function addNotificationHandler(string $method, NotificationHandlerInterface $handler): self
    {
        $this->customNotificationHandlers[$method] = $handler;

        return $this;
    }

    public function build(): Server
    {
        Assert::that($this->serverInfo)->not()->isNull('Server info must be set before build() via setServerInfo().');

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
            completions: null !== $this->completionStore ? [] : null,
            logging: [],
            prompts: [] !== $this->prompts ? [] : null,
            resources: ([] !== $this->resources || [] !== $this->resourceTemplates) ? [] : null,
            tools: [] !== $this->tools ? [] : null,
        );
    }

    /**
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>>
     */
    private function buildRequestHandlers(Implementation $serverInfo, ServerCapabilities $capabilities): array
    {
        $defaults = [
            InitializeRequest::method() => new InitializeRequestHandler($serverInfo, $capabilities, $this->instructions),
            PingRequest::method() => new PingRequestHandler(),
        ];

        if ([] !== $this->tools) {
            $toolStore = new ToolStore($this->tools);
            $defaults[ListToolsRequest::method()] = new ListToolsRequestHandler($toolStore);
            $defaults[CallToolRequest::method()] = new CallToolRequestHandler($toolStore);
        }

        if ([] !== $this->prompts) {
            $promptStore = new PromptStore($this->prompts);
            $defaults[ListPromptsRequest::method()] = new ListPromptsRequestHandler($promptStore);
            $defaults[GetPromptRequest::method()] = new GetPromptRequestHandler($promptStore);
        }

        if ([] !== $this->resources) {
            $resourceStore = new ResourceStore($this->resources);
            $defaults[ListResourcesRequest::method()] = new ListResourcesRequestHandler($resourceStore);
            $defaults[ReadResourceRequest::method()] = new ReadResourceRequestHandler($resourceStore);
        }

        if ([] !== $this->resourceTemplates) {
            $defaults[ListResourceTemplatesRequest::method()] = new ListResourceTemplatesRequestHandler(
                new ResourceTemplateStore($this->resourceTemplates),
            );
        }

        if (null !== $this->completionStore) {
            $defaults[CompleteRequest::method()] = new CompleteRequestHandler($this->completionStore);
        }

        return [...$defaults, ...$this->customRequestHandlers];
    }
}
