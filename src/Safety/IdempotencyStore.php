<?php

declare(strict_types=1);

namespace Daraja\Safety;

/**
 * Remembers which callbacks have been handled so a retried or duplicated delivery is acknowledged
 * but not processed twice.
 *
 * The contract is a small state machine per key:
 *   begin() -> Acquired  : caller does the work, then complete() on success or release() on failure
 *   begin() -> InProgress: another worker holds the key (until its lock expires)
 *   begin() -> AlreadyProcessed: finished earlier
 *
 * begin() must be atomic: two concurrent callers can never both get Acquired for the same key.
 */
interface IdempotencyStore
{
    public function begin(string $key, int $lockSeconds = 300): BeginResult;

    public function complete(string $key): void;

    /** Give the key back after a failure so the next delivery can try again. */
    public function release(string $key): void;
}
