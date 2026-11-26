<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Support\Json;

final readonly class MockResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = ['Content-Type' => 'application/json'],
    ) {
    }

    /** @param array<mixed> $data */
    public static function json(int $status, array $data): self
    {
        return new self($status, Json::encode($data));
    }

    public static function html(string $html): self
    {
        return new self(200, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
