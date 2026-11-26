<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Support\Clock;
use Daraja\Support\SystemClock;

/** Per-process store for tests and single-process scripts. It forgets everything when the process ends. */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{state: 'processing'|'completed', lockedUntil: int}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function begin(string $key, int $lockSeconds = 300): BeginResult
    {
        $now = $this->clock->now()->getTimestamp();
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || ($entry['state'] === 'processing' && $entry['lockedUntil'] <= $now)) {
            $this->entries[$key] = ['state' => 'processing', 'lockedUntil' => $now + $lockSeconds];

            return BeginResult::Acquired;
        }

        return $entry['state'] === 'completed' ? BeginResult::AlreadyProcessed : BeginResult::InProgress;
    }

    public function complete(string $key): void
    {
        $this->entries[$key] = ['state' => 'completed', 'lockedUntil' => 0];
    }

    public function release(string $key): void
    {
        if (($this->entries[$key]['state'] ?? null) === 'processing') {
            unset($this->entries[$key]);
        }
    }
}
