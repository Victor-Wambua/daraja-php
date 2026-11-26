<?php

declare(strict_types=1);

namespace Daraja\Mock;

final readonly class MockRequest
{
    /** @param array<string, string> $headers lower-case header name => value */
    public function __construct(
        public string $method,
        public string $path,
        public string $query,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
