<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Exception\ConfigurationException;
use Daraja\Support\Clock;
use Daraja\Support\SystemClock;

/**
 * Idempotency store on PDO. Written with plain portable SQL (no vendor upsert syntax), tested on SQLite.
 * Atomicity comes from the table's primary key: the first INSERT wins and every concurrent one fails.
 *
 * Use the same database as your orders table if you can. And keep a UNIQUE constraint on the M-Pesa
 * receipt number in your ledger as well: this store protects the callback handler, the constraint protects the money.
 */
final class PdoIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'daraja_idempotency',
        private readonly Clock $clock = new SystemClock(),
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) !== 1) {
            throw new ConfigurationException('Table name must be a plain identifier.');
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Create the table if it does not exist. SQLite and MySQL/MariaDB accept this statement; adapt the types for others. */
    public function migrate(): void
    {
        $this->pdo->exec(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                idem_key VARCHAR(191) NOT NULL PRIMARY KEY,
                state VARCHAR(16) NOT NULL,
                locked_until BIGINT NOT NULL,
                created_at BIGINT NOT NULL,
                completed_at BIGINT NULL
            )',
            $this->table,
        ));
    }

    public function begin(string $key, int $lockSeconds = 300): BeginResult
    {
        $this->assertKey($key);
        $now = $this->clock->now()->getTimestamp();

        for ($i = 0; $i < 3; ++$i) {
            try {
                $insert = $this->pdo->prepare(\sprintf(
                    "INSERT INTO %s (idem_key, state, locked_until, created_at) VALUES (?, 'processing', ?, ?)",
                    $this->table,
                ));
                $insert->execute([$key, $now + $lockSeconds, $now]);

                return BeginResult::Acquired;
            } catch (\PDOException $e) {
                if (!str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }

            // The key exists. If its lock has expired, take it over; the WHERE clause makes the takeover atomic too.
            $takeover = $this->pdo->prepare(\sprintf(
                "UPDATE %s SET locked_until = ? WHERE idem_key = ? AND state = 'processing' AND locked_until <= ?",
                $this->table,
            ));
            $takeover->execute([$now + $lockSeconds, $key, $now]);
            if ($takeover->rowCount() === 1) {
                return BeginResult::Acquired;
            }

            $select = $this->pdo->prepare(\sprintf('SELECT state FROM %s WHERE idem_key = ?', $this->table));
            $select->execute([$key]);
            $state = $select->fetchColumn();
            if ($state === 'completed') {
                return BeginResult::AlreadyProcessed;
            }
            if ($state === 'processing') {
                return BeginResult::InProgress;
            }
            // Row vanished between our statements (released by its owner): loop and try the INSERT again.
        }

        return BeginResult::InProgress;
    }

    public function complete(string $key): void
    {
        $this->assertKey($key);
        $now = $this->clock->now()->getTimestamp();
        $update = $this->pdo->prepare(\sprintf(
            "UPDATE %s SET state = 'completed', completed_at = ?, locked_until = 0 WHERE idem_key = ?",
            $this->table,
        ));
        $update->execute([$now, $key]);
    }

    public function release(string $key): void
    {
        $this->assertKey($key);
        $delete = $this->pdo->prepare(\sprintf("DELETE FROM %s WHERE idem_key = ? AND state = 'processing'", $this->table));
        $delete->execute([$key]);
    }

    /** Delete completed keys older than the given age. Run it from a scheduled job; Daraja retries do not span months. */
    public function purgeCompletedOlderThan(int $seconds): int
    {
        $cutoff = $this->clock->now()->getTimestamp() - $seconds;
        $delete = $this->pdo->prepare(\sprintf("DELETE FROM %s WHERE state = 'completed' AND completed_at < ?", $this->table));
        $delete->execute([$cutoff]);

        return $delete->rowCount();
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || \strlen($key) > 191) {
            throw new \InvalidArgumentException('Idempotency key must be 1 to 191 bytes.');
        }
    }
}
