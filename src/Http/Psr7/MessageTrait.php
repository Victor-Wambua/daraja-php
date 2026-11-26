<?php

declare(strict_types=1);

namespace Daraja\Http\Psr7;

use Psr\Http\Message\StreamInterface;

/**
 * Shared header/body handling for the small PSR-7 Request and Response in this package.
 */
trait MessageTrait
{
    private string $protocol = '1.1';

    /** @var array<string, list<string>> keyed by lower-case name */
    private array $headers = [];

    /** @var array<string, string> lower-case name => original casing */
    private array $headerNames = [];

    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $lower => $values) {
            $out[$this->headerNames[$lower]] = $values;
        }

        return $out;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->setHeader($name, $value);

        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;
        $lower = strtolower($name);
        $existing = $new->headers[$lower] ?? [];
        $new->setHeader($name, $value);
        $new->headers[$lower] = array_merge($existing, $new->headers[$lower]);

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        $lower = strtolower($name);
        unset($new->headers[$lower], $new->headerNames[$lower]);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /** @param array<string, string|array<string>> $headers */
    private function initHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $this->setHeader((string) $name, $value);
        }
    }

    /** @param string|array<string> $value */
    private function setHeader(string $name, mixed $value): void
    {
        if (preg_match('/^[A-Za-z0-9\'`#$%&*+.^_|~!-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid header name.');
        }
        $values = [];
        foreach ((array) $value as $v) {
            $v = trim((string) $v, " \t");
            if (preg_match("/[\r\n\0]/", $v) === 1) {
                throw new \InvalidArgumentException('Header values must not contain line breaks.');
            }
            $values[] = $v;
        }
        $lower = strtolower($name);
        $this->headers[$lower] = $values;
        $this->headerNames[$lower] = $name;
    }
}
