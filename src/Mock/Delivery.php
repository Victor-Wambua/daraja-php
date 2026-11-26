<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** One callback that the mock owes your server, with its retry state. */
final readonly class Delivery
{
    public function __construct(
        public int $id,
        public int $transactionId,
        public string $label,
        public string $url,
        public string $payload,
        public string $status,
        public int $attempts,
        public int $maxAttempts,
        public float $nextAttemptAt,
        public ?int $lastHttpStatus,
        public string $lastError,
        public float $createdAt,
        public ?float $deliveredAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $s = static fn (string $k): string => \is_scalar($row[$k] ?? null) ? (string) $row[$k] : '';
        $i = static fn (string $k): int => \is_numeric($row[$k] ?? null) ? (int) $row[$k] : 0;
        $f = static fn (string $k): ?float => \is_numeric($row[$k] ?? null) ? (float) $row[$k] : null;

        return new self(
            id: $i('id'),
            transactionId: $i('transaction_id'),
            label: $s('label'),
            url: $s('url'),
            payload: $s('payload'),
            status: $s('status'),
            attempts: $i('attempts'),
            maxAttempts: $i('max_attempts'),
            nextAttemptAt: $f('next_attempt_at') ?? 0.0,
            lastHttpStatus: \is_numeric($row['last_http_status'] ?? null) ? (int) $row['last_http_status'] : null,
            lastError: $s('last_error'),
            createdAt: $f('created_at') ?? 0.0,
            deliveredAt: $f('delivered_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transactionId' => $this->transactionId,
            'label' => $this->label,
            'url' => $this->url,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'nextAttemptAt' => $this->nextAttemptAt,
            'lastHttpStatus' => $this->lastHttpStatus,
            'lastError' => $this->lastError,
            'createdAt' => $this->createdAt,
            'deliveredAt' => $this->deliveredAt,
            'payload' => $this->payload,
        ];
    }
}
