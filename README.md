# Nexus MCP SDK: Server

[![PHP](http://poser.pugx.org/nexusphp/mcp-server/require/php)](https://packagist.org/packages/nexusphp/mcp-server)
[![Latest Stable Version](http://poser.pugx.org/nexusphp/mcp-server/v)](https://packagist.org/packages/nexusphp/mcp-server)
[![License](https://img.shields.io/github/license/NexusPHP/mcp)](LICENSE)

The server half of the [Nexus MCP SDK](https://github.com/NexusPHP/mcp): a fluent `ServerBuilder`
for registering tools, prompts, resources, and completions, a `Server` that runs them over stdio or
Streamable HTTP, and the resource-server side of OAuth.

> [!IMPORTANT]
> This repository is a read-only subtree split of [NexusPHP/mcp](https://github.com/NexusPHP/mcp).
> Open issues and pull requests there. Anything opened here is closed automatically with the same pointer.

## Installation

```bash
composer require nexusphp/mcp-server
```

This pulls in `nexusphp/mcp-core`. The umbrella `nexusphp/mcp` ships the client and the official
extensions alongside it.

## What is inside

| Namespace | Contents |
| --- | --- |
| `Nexus\Mcp\Server` | `ServerBuilder`, `Server`, `ServerContext`, and the cursor paginator |
| `Nexus\Mcp\Server\Tool`, `Prompt`, `Resource`, `Completion` | The per-feature stores and the consumer-facing executor and reader interfaces |
| `Nexus\Mcp\Server\Attribute` and `Nexus\Mcp\Server\Discovery` | `#[AsTool]` and friends, and the scanner that turns them into definitions |
| `Nexus\Mcp\Server\Handler` and `Nexus\Mcp\Server\Dispatch` | The built-in request and notification handlers and the dispatch kernel |
| `Nexus\Mcp\Server\Transport` | `StdioServerTransport` and the PSR-15 `StreamableHttpServerTransport` |
| `Nexus\Mcp\Server\Auth` | Bearer authentication middleware, token validators, and the protected-resource metadata endpoint |
| `Nexus\Mcp\Server\Subscription` | The `subscriptions/listen` stream store |
| `Nexus\Mcp\Server\Extension` and `Nexus\Mcp\Server\Validation` | The server-side extension gate and the JSON Schema validator |

## Documentation

- [Getting started](https://nexusphp.github.io/mcp/getting-started/) and the
  [Server API overview](https://nexusphp.github.io/mcp/server/).
- [Transports](https://nexusphp.github.io/mcp/transports/) and the
  [resource server](https://nexusphp.github.io/mcp/auth/server/) guide.
- [API reference](https://nexusphp.github.io/mcp/api/).
- [Changelog](https://github.com/NexusPHP/mcp/blob/1.x/CHANGELOG.md) and
  [versioning policy](https://github.com/NexusPHP/mcp/blob/1.x/VERSIONING.md), shared by every component.

## License

Released under the [MIT License](LICENSE).
