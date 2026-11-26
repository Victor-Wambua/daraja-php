<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** SQLite persistence for the mock. Each PHP built-in server request is a fresh process, so state lives here. */
final class Store
{
    private const TXN_COLUMNS = [
        'status', 'result_code', 'result_desc', 'receipt', 'resolved_at', 'expires_at', 'callback_withheld',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /** Open (and create if needed) the SQLite database. Use ":memory:" only for in-process tests. */
    public static function open(string $path): self
    {
        $pdo = new \PDO('sqlite:' . $path);
        $store = new self($pdo);
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $store->migrate();

        return $store;
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                status TEXT NOT NULL,
                merchant_request_id TEXT NOT NULL DEFAULT \'\',
                checkout_request_id TEXT NOT NULL DEFAULT \'\',
                conversation_id TEXT NOT NULL DEFAULT \'\',
                originator_conversation_id TEXT NOT NULL DEFAULT \'\',
                shortcode TEXT NOT NULL DEFAULT \'\',
                party_b TEXT NOT NULL DEFAULT \'\',
                txn_type TEXT NOT NULL DEFAULT \'\',
                amount_cents INTEGER NOT NULL,
                msisdn TEXT NOT NULL DEFAULT \'\',
                account_reference TEXT NOT NULL DEFAULT \'\',
                description TEXT NOT NULL DEFAULT \'\',
                callback_url TEXT NOT NULL DEFAULT \'\',
                result_code INTEGER NULL,
                result_desc TEXT NOT NULL DEFAULT \'\',
                receipt TEXT NOT NULL DEFAULT \'\',
                created_at REAL NOT NULL,
                resolved_at REAL NULL,
                expires_at REAL NULL,
                callback_withheld INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_txn_checkout ON transactions (checkout_request_id);
            CREATE INDEX IF NOT EXISTS idx_txn_receipt ON transactions (receipt);
            CREATE TABLE IF NOT EXISTS deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id INTEGER NOT NULL,
                label TEXT NOT NULL,
                url TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL,
                next_attempt_at REAL NOT NULL,
                last_http_status INTEGER NULL,
                last_error TEXT NOT NULL DEFAULT \'\',
                created_at REAL NOT NULL,
                delivered_at REAL NULL
            );
            CREATE INDEX IF NOT EXISTS idx_delivery_due ON deliveries (status, next_attempt_at);
            CREATE TABLE IF NOT EXISTS c2b_urls (
                shortcode TEXT PRIMARY KEY,
                confirmation_url TEXT NOT NULL,
                validation_url TEXT NOT NULL,
                response_type TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS wire_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                at REAL NOT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                status INTEGER NOT NULL,
                ms INTEGER NOT NULL,
                request_body TEXT NOT NULL,
                response_body TEXT NOT NULL
            );',
        );
    }

    public function reset(): void
    {
        $this->pdo->exec('DELETE FROM transactions; DELETE FROM deliveries; DELETE FROM c2b_urls; DELETE FROM wire_log;');
    }

    /** @param array<string, string|int|float|null> $fields column => value, columns must be trusted constants */
    public function insertTransaction(array $fields): int
    {
        $columns = array_keys($fields);
        $statement = $this->pdo->prepare(\sprintf(
            'INSERT INTO transactions (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, \count($columns), '?')),
        ));
        $statement->execute(array_values($fields));

        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(int $id): ?Txn
    {
        return $this->oneTxn('SELECT * FROM transactions WHERE id = ?', [$id]);
    }

    public function transactionByCheckoutId(string $checkoutRequestId): ?Txn
    {
        return $this->oneTxn("SELECT * FROM transactions WHERE kind = 'stk' AND checkout_request_id = ?", [$checkoutRequestId]);
    }

    public function transactionByReceipt(string $receipt): ?Txn
    {
        return $this->oneTxn("SELECT * FROM transactions WHERE receipt = ? AND receipt <> ''", [$receipt]);
    }

    /** @param array<string, string|int|float|null> $fields */
    public function updateTransaction(int $id, array $fields): void
    {
        $sets = [];
        foreach (array_keys($fields) as $column) {
            if (!\in_array($column, self::TXN_COLUMNS, true)) {
                throw new \InvalidArgumentException('Unknown column ' . $column);
            }
            $sets[] = $column . ' = ?';
        }
        $statement = $this->pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute([...array_values($fields), $id]);
    }

    /**
     * Resolve a pending STK prompt exactly once. Returns false if someone else resolved it first.
     *
     * @param array<string, string|int|float|null> $fields
     */
    public function resolvePending(int $id, array $fields): bool
    {
        $sets = [];
        foreach (array_keys($fields) as $column) {
            if (!\in_array($column, self::TXN_COLUMNS, true)) {
                throw new \InvalidArgumentException('Unknown column ' . $column);
            }
            $sets[] = $column . ' = ?';
        }
        $statement = $this->pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . " WHERE id = ? AND status = 'pending'");
        $statement->execute([...array_values($fields), $id]);

        return $statement->rowCount() === 1;
    }

    /** @return list<Txn> */
    public function latestTransactions(int $limit): array
    {
        return $this->manyTxn('SELECT * FROM transactions ORDER BY id DESC LIMIT ' . max(1, $limit), []);
    }

    /** @return list<Txn> */
    public function pendingStk(): array
    {
        return $this->manyTxn("SELECT * FROM transactions WHERE kind = 'stk' AND status = 'pending' ORDER BY id ASC", []);
    }

    /** @return list<Txn> */
    public function expiredPendingStk(float $now): array
    {
        return $this->manyTxn("SELECT * FROM transactions WHERE kind = 'stk' AND status = 'pending' AND expires_at <= ?", [$now]);
    }

    public function addDelivery(int $transactionId, string $label, string $url, string $payload, int $maxAttempts, float $now): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO deliveries (transaction_id, label, url, payload, status, attempts, max_attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, ?)",
        );
        $statement->execute([$transactionId, $label, $url, $payload, $maxAttempts, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delivery(int $id): ?Delivery
    {
        $statement = $this->pdo->prepare('SELECT * FROM deliveries WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return \is_array($row) ? Delivery::fromRow($this->stringKeys($row)) : null;
    }

    /** @return list<Delivery> */
    public function dueDeliveries(float $now): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM deliveries WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id ASC LIMIT 20");
        $statement->execute([$now]);

        return $this->mapDeliveries($statement->fetchAll());
    }

    /** @return list<Delivery> */
    public function latestDeliveries(int $limit): array
    {
        $statement = $this->pdo->query('SELECT * FROM deliveries ORDER BY id DESC LIMIT ' . max(1, $limit));

        return $statement === false ? [] : $this->mapDeliveries($statement->fetchAll());
    }

    /**
     * Claim a pending delivery for an attempt. Returns false if another worker already took it.
     */
    public function claimDelivery(int $id, float $now, float $leaseSeconds): bool
    {
        $statement = $this->pdo->prepare("UPDATE deliveries SET next_attempt_at = ? WHERE id = ? AND status = 'pending' AND next_attempt_at <= ?");
        $statement->execute([$now + $leaseSeconds, $id, $now]);

        return $statement->rowCount() === 1;
    }

    public function markDelivered(int $id, int $httpStatus, float $now): void
    {
        $statement = $this->pdo->prepare("UPDATE deliveries SET status = 'delivered', attempts = attempts + 1, last_http_status = ?, last_error = '', delivered_at = ? WHERE id = ?");
        $statement->execute([$httpStatus, $now, $id]);
    }

    public function markAttemptFailed(int $id, ?int $httpStatus, string $error, ?float $retryAt): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE deliveries SET attempts = attempts + 1, last_http_status = ?, last_error = ?,
                status = CASE WHEN ? IS NULL THEN 'failed' ELSE 'pending' END,
                next_attempt_at = COALESCE(?, next_attempt_at)
             WHERE id = ?",
        );
        $statement->execute([$httpStatus, $error, $retryAt, $retryAt, $id]);
    }

    /** Put a finished delivery back in the queue (the console's "Redeliver" button). */
    public function requeueDelivery(int $id, int $maxAttempts, float $now): void
    {
        $statement = $this->pdo->prepare("UPDATE deliveries SET status = 'pending', attempts = 0, max_attempts = ?, next_attempt_at = ?, last_error = '', delivered_at = NULL WHERE id = ?");
        $statement->execute([$maxAttempts, $now, $id]);
    }

    public function saveC2bUrls(string $shortCode, string $confirmationUrl, string $validationUrl, string $responseType): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO c2b_urls (shortcode, confirmation_url, validation_url, response_type) VALUES (?, ?, ?, ?)');
        $statement->execute([$shortCode, $confirmationUrl, $validationUrl, $responseType]);
    }

    /** @return array{confirmation: string, validation: string, responseType: string}|null */
    public function c2bUrls(string $shortCode): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM c2b_urls WHERE shortcode = ?');
        $statement->execute([$shortCode]);
        $row = $statement->fetch();
        if (!\is_array($row)) {
            return null;
        }
        $row = $this->stringKeys($row);

        return [
            'confirmation' => \is_string($row['confirmation_url'] ?? null) ? $row['confirmation_url'] : '',
            'validation' => \is_string($row['validation_url'] ?? null) ? $row['validation_url'] : '',
            'responseType' => \is_string($row['response_type'] ?? null) ? $row['response_type'] : 'Completed',
        ];
    }

    public function logWire(float $at, string $method, string $path, int $status, int $ms, string $requestBody, string $responseBody): void
    {
        $statement = $this->pdo->prepare('INSERT INTO wire_log (at, method, path, status, ms, request_body, response_body) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$at, $method, $path, $status, $ms, mb_substr($requestBody, 0, 4000), mb_substr($responseBody, 0, 4000)]);
        $this->pdo->exec('DELETE FROM wire_log WHERE id <= (SELECT MAX(id) FROM wire_log) - 300');
    }

    /** @return list<array<string, mixed>> */
    public function latestWire(int $limit): array
    {
        $statement = $this->pdo->query('SELECT * FROM wire_log ORDER BY id DESC LIMIT ' . max(1, $limit));
        if ($statement === false) {
            return [];
        }
        $out = [];
        foreach ($statement->fetchAll() as $row) {
            if (\is_array($row)) {
                $out[] = $this->stringKeys($row);
            }
        }

        return $out;
    }

    /**
     * @param list<scalar> $params
     */
    private function oneTxn(string $sql, array $params): ?Txn
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return \is_array($row) ? Txn::fromRow($this->stringKeys($row)) : null;
    }

    /**
     * @param list<scalar> $params
     *
     * @return list<Txn>
     */
    private function manyTxn(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll() as $row) {
            if (\is_array($row)) {
                $out[] = Txn::fromRow($this->stringKeys($row));
            }
        }

        return $out;
    }

    /**
     * @param mixed $rows
     *
     * @return list<Delivery>
     */
    private function mapDeliveries(mixed $rows): array
    {
        $out = [];
        if (\is_array($rows)) {
            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $out[] = Delivery::fromRow($this->stringKeys($row));
                }
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
