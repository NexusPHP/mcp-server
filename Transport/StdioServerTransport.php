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

namespace Nexus\Mcp\Server\Transport;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Transport\LineDuplex;
use Nexus\Mcp\Core\Transport\LineReader;
use Nexus\Mcp\Core\Transport\ListenerHandleInterface;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stdio MCP server transport over line-framed JSON-RPC on STDIN/STDOUT.
 */
final class StdioServerTransport implements TransportInterface
{
    private readonly LineDuplex $duplex;

    public function __construct(
        private readonly ReadableStream $stdin = new ReadableResourceStream(\STDIN),
        private readonly WritableStream $stdout = new WritableResourceStream(\STDOUT),
        LoggerInterface $logger = new NullLogger(),
        int $maxLineBytes = LineReader::DEFAULT_MAX_LINE_BYTES,
    ) {
        $this->duplex = new LineDuplex(
            hostTransport: self::class,
            label: 'Stdio server',
            logger: $logger,
            maxLineBytes: $maxLineBytes,
            onParseFailure: function (JsonRpcErrorResponse $response): void {
                $this->send($response);
            },
            onBeforeClose: function (): void {
                $this->stdin->close();
                $this->stdout->close();
            },
        );
    }

    #[\Override]
    public function start(): void
    {
        $this->duplex->start($this->stdin, $this->stdout);
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        $this->duplex->send($message);
    }

    #[\Override]
    public function close(): void
    {
        $this->duplex->close();
    }

    #[\Override]
    public function onMessage(\Closure $listener): ListenerHandleInterface
    {
        return $this->duplex->onMessage($listener);
    }

    #[\Override]
    public function onError(\Closure $listener): ListenerHandleInterface
    {
        return $this->duplex->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): ListenerHandleInterface
    {
        return $this->duplex->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): ListenerHandleInterface
    {
        return $this->duplex->onClose($listener);
    }
}
