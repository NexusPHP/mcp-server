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
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Nexus\Assert\Assert;

/**
 * Readable socket fed by a PHP subprocess copying a STDIN that refuses non-blocking mode.
 *
 * @internal
 *
 * @implements \IteratorAggregate<int, string>
 */
final class RelayedStdinStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    /**
     * Without `pipe.blocking`, PHP on Windows polls an idle pipe for a bounded time and then reports EOF.
     */
    private const string RELAY = <<<'PHP'
        $stdin = fopen('php://stdin', 'rb', false, stream_context_create(['pipe' => ['blocking' => true]]));

        while (true) {
            $chunk = fgets($stdin, 8192);

            if (false === $chunk || fwrite(STDOUT, $chunk) === false) {
                break;
            }
        }
        PHP;

    /**
     * @var resource
     */
    private readonly mixed $relay;

    private readonly ReadableResourceStream $socket;

    /**
     * @param resource $stdin
     */
    public function __construct(mixed $stdin)
    {
        $relay = proc_open([\PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-r', self::RELAY], [0 => $stdin, 1 => ['socket'], 2 => \STDERR], $pipes);
        Assert::that($relay)->isResource('The STDIN relay subprocess failed to start.');

        $this->relay = $relay;
        $this->socket = new ReadableResourceStream($pipes[1]);
    }

    public function __destruct()
    {
        $this->close();
    }

    #[\Override]
    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->socket->read($cancellation);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->socket->isReadable();
    }

    #[\Override]
    public function close(): void
    {
        if (! \is_resource($this->relay)) {
            return;
        }

        proc_terminate($this->relay);
        $this->socket->close();
        proc_close($this->relay);
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }
}
