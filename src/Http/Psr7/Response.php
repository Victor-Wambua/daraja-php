<?php

declare(strict_types=1);

namespace Daraja\Http\Psr7;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    use MessageTrait;

    private const PHRASES = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 400 => 'Bad Request', 401 => 'Unauthorized',
        403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    private int $status;
    private string $reason;

    /** @param array<string, string|array<string>> $headers */
    public function __construct(int $status = 200, array $headers = [], ?StreamInterface $body = null, string $reason = '')
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status code.');
        }
        $this->status = $status;
        $this->reason = $reason !== '' ? $reason : (self::PHRASES[$status] ?? '');
        $this->body = $body ?? new Stream();
        $this->initHeaders($headers);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->status = $code;
        $new->reason = $reasonPhrase !== '' ? $reasonPhrase : (self::PHRASES[$code] ?? '');

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
}
