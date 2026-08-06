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

namespace Nexus\Mcp\Server\Transport\Http;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Psr\Http\Message\StreamInterface;

/**
 * Read-only, non-seekable PSR-7 body for a Server-Sent Events stream. The transport pushes frames while
 * the consumer reads, and a read blocks (suspending the fiber) until the next frame is pushed or the
 * stream ends. A read that stays idle for the keep-alive interval yields an SSE comment frame so the
 * connection is not reaped.
 *
 * @internal
 */
final class SseResponseStream implements StreamInterface
{
    private const int CHUNK = 8_192;
    private const string KEEP_ALIVE_FRAME = ": keep-alive\n\n";

    private string $buffer = '';
    private bool $ended = false;
    private int $offset = 0;

    /**
     * @var null|DeferredFuture<null>
     */
    private ?DeferredFuture $reader = null;

    /**
     * @param float            $keepAliveInterval Seconds an idle read waits before yielding a keep-alive frame
     * @param \Closure(): void $onClose           Runs when the consumer closes the body (e.g. client disconnect)
     */
    public function __construct(private readonly float $keepAliveInterval, private readonly \Closure $onClose)
    {
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * Appends a frame for the consumer to read. A frame pushed after the stream ended is discarded.
     */
    public function push(string $frame): void
    {
        if ($this->ended) {
            return;
        }

        $this->buffer .= $frame;
        $this->resumeReader();
    }

    /**
     * Marks the stream complete: once the buffered frames are drained, the consumer reaches end-of-body.
     */
    public function end(): void
    {
        $this->ended = true;
        $this->resumeReader();
    }

    #[\Override]
    public function close(): void
    {
        $this->end();
        ($this->onClose)();
    }

    #[\Override]
    public function detach()
    {
        $this->close();

        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return $this->offset;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->ended && '' === $this->buffer;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('An SSE response body is not seekable.');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new \RuntimeException('An SSE response body is not seekable.');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('An SSE response body is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        if ('' === $this->buffer && ! $this->ended) {
            $this->reader = new DeferredFuture();

            try {
                $this->reader->getFuture()->await(new TimeoutCancellation($this->keepAliveInterval));
            } catch (CancelledException) {
                // Buffering it rather than returning it keeps the read honouring `$length` and `tell()`.
                // A push during the await completes the reader, so reaching here leaves the buffer empty.
                $this->buffer = self::KEEP_ALIVE_FRAME;
            }
        }

        $data = substr($this->buffer, 0, $length);

        if ($data === $this->buffer) {
            $this->buffer = '';
        } else {
            $this->buffer = substr($this->buffer, \strlen($data));
        }

        $this->offset += \strlen($data);

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = '';

        do {
            $chunk = $this->read(self::CHUNK);
            $contents .= $chunk;
        } while ('' !== $chunk);

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }

    private function resumeReader(): void
    {
        $reader = $this->reader;
        $this->reader = null;
        $reader?->complete();
    }
}
