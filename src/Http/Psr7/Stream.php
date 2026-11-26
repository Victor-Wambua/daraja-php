<?php

declare(strict_types=1);

namespace Daraja\Http\Psr7;

use Psr\Http\Message\StreamInterface;

/** In-memory PSR-7 stream. Bodies in this library are small JSON documents. */
final class Stream implements StreamInterface
{
    private string $buffer;
    private int $position = 0;
    private bool $detached = false;

    public function __construct(string $contents = '')
    {
        $this->buffer = $contents;
    }

    public function __toString(): string
    {
        return $this->detached ? '' : $this->buffer;
    }

    public function close(): void
    {
        $this->detach();
    }

    public function detach()
    {
        $this->detached = true;
        $this->buffer = '';
        $this->position = 0;

        return null;
    }

    public function getSize(): ?int
    {
        return $this->detached ? null : \strlen($this->buffer);
    }

    public function tell(): int
    {
        $this->assertAttached();

        return $this->position;
    }

    public function eof(): bool
    {
        return $this->detached || $this->position >= \strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return !$this->detached;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => \strlen($this->buffer) + $offset,
            default => throw new \InvalidArgumentException('Invalid whence.'),
        };
        if ($target < 0) {
            throw new \RuntimeException('Cannot seek before the start of the stream.');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return !$this->detached;
    }

    public function write(string $string): int
    {
        $this->assertAttached();
        $this->buffer = substr($this->buffer, 0, $this->position) . $string . substr($this->buffer, $this->position + \strlen($string));
        $this->position += \strlen($string);

        return \strlen($string);
    }

    public function isReadable(): bool
    {
        return !$this->detached;
    }

    public function read(int $length): string
    {
        $this->assertAttached();
        $chunk = substr($this->buffer, $this->position, $length);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $this->assertAttached();
        $rest = substr($this->buffer, $this->position);
        $this->position = \strlen($this->buffer);

        return $rest;
    }

    public function getMetadata(?string $key = null)
    {
        $meta = ['seekable' => !$this->detached, 'mode' => 'r+'];

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    private function assertAttached(): void
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached.');
        }
    }
}
