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
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Core\Transport\TransportEvents;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Core\Transport\TransportState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\ByteStream\splitLines;

/**
 * Stdio MCP server transport over line-framed JSON-RPC on STDIN/STDOUT.
 */
final class StdioServerTransport implements TransportInterface
{
    private TransportState $state = TransportState::Idle;
    private readonly TransportEvents $events;

    public function __construct(
        private readonly ReadableStream $stdin = new ReadableResourceStream(\STDIN),
        private readonly WritableStream $stdout = new WritableResourceStream(\STDOUT),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->events = new TransportEvents(
            onChange: function (string $kind, string $action, int $count): void {
                $verb = match ($action) {
                    'register' => 'registered',
                    'dispose' => 'disposed',
                };
                $article = 'error' === $kind ? 'an' : 'a';
                $this->logger->debug(
                    \sprintf('Stdio transport %s %s %s listener. {count} active.', $verb, $article, $kind),
                    ['count' => $count],
                );
            },
        );
    }

    #[\Override]
    public function start(): void
    {
        match ($this->state) {
            TransportState::Running => throw new TransportAlreadyStartedException(transport: self::class),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'start'),
            TransportState::Idle => null,
        };

        $this->state = TransportState::Running;
        $this->logger->info('Stdio transport started. Reading from stdin.');

        EventLoop::queue($this->readLoop(...));
    }

    /**
     * @throws \JsonException
     * @throws StreamException
     * @throws TransportAlreadyClosedException
     * @throws TransportNotStartedException
     */
    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        match ($this->state) {
            TransportState::Idle => throw new TransportNotStartedException(operation: 'send'),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'send'),
            TransportState::Running => null,
        };

        try {
            $this->stdout->write(json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
            $this->logSentMessage($message);
        } catch (\Throwable $e) {
            $this->logSendError($message, $e);
            $this->close();

            throw $e;
        }
    }

    #[\Override]
    public function close(): void
    {
        if (TransportState::Closed === $this->state) {
            return;
        }

        $this->logger->info(
            'Stdio transport closing from {priorState} state.',
            ['priorState' => $this->state->name],
        );
        $this->state = TransportState::Closed;

        $this->stdin->close();
        $this->stdout->close();

        $this->events->emitClose();
        $this->logger->info('Stdio transport closed.');
    }

    #[\Override]
    public function sessionId(): ?string
    {
        return null;
    }

    #[\Override]
    public function label(): string
    {
        return 'stdio';
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onMessage($listener);
    }

    #[\Override]
    public function onError(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onClose($listener);
    }

    private function readLoop(): void
    {
        try {
            foreach (splitLines($this->stdin) as $line) {
                if ('' === $line) {
                    continue;
                }

                $this->processLine($line);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Stdio transport read loop failed. Closing.', ['exception' => $e]);
            $this->events->emitError($e);
        } finally {
            try {
                $this->events->emitDrain();
            } finally {
                $this->close();
            }
        }
    }

    private function processLine(string $line): void
    {
        try {
            $decodedJson = json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->debug('Stdio transport skipped malformed JSON line.', ['exception' => $e]);

            return;
        }

        try {
            Assert::that($decodedJson)->isMap('JSON-RPC envelope must be a JSON object, {type} given.');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Stdio transport rejected a non-object envelope.', ['exception' => $e]);
            $this->events->emitError($e);

            return;
        }

        $this->logger->debug('Stdio transport received a JSON-RPC envelope.');

        try {
            $this->events->emitMessage($decodedJson);
        } catch (\Throwable $e) {
            $this->events->emitError($e);
        }
    }

    private function logSentMessage(JsonRpcMessage $message): void
    {
        match (true) {
            $message instanceof JsonRpcRequest => $this->logger->debug(
                'Stdio transport sent "{method}" request with ID "{id}".',
                ['method' => $message::method(), 'id' => $message->id->id],
            ),
            $message instanceof JsonRpcNotification => $this->logger->debug(
                'Stdio transport sent "{method}" notification.',
                ['method' => $message::method()],
            ),
            $message instanceof JsonRpcResultResponse => $this->logger->debug(
                'Stdio transport sent a result response for request ID "{id}".',
                ['id' => $message->id->id],
            ),
            default => null === $message->id
                ? $this->logger->debug('Stdio transport sent an error response with no correlatable ID.')
                : $this->logger->debug(
                    'Stdio transport sent an error response for request ID "{id}".',
                    ['id' => $message->id->id],
                ),
        };
    }

    private function logSendError(JsonRpcMessage $message, \Throwable $error): void
    {
        match (true) {
            $message instanceof JsonRpcRequest => $this->logger->error(
                'Stdio transport failed to send "{method}" request with ID "{id}". Closing.',
                ['exception' => $error, 'method' => $message::method(), 'id' => $message->id->id],
            ),
            $message instanceof JsonRpcNotification => $this->logger->error(
                'Stdio transport failed to send "{method}" notification. Closing.',
                ['exception' => $error, 'method' => $message::method()],
            ),
            $message instanceof JsonRpcResultResponse => $this->logger->error(
                'Stdio transport failed to send result response for request ID "{id}". Closing.',
                ['exception' => $error, 'id' => $message->id->id],
            ),
            default => null === $message->id
                ? $this->logger->error(
                    'Stdio transport failed to send an error response with no correlatable ID. Closing.',
                    ['exception' => $error],
                )
                : $this->logger->error(
                    'Stdio transport failed to send an error response for request ID "{id}". Closing.',
                    ['exception' => $error, 'id' => $message->id->id],
                ),
        };
    }
}
