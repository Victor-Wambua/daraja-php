<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Support\Clock;

/** What can happen to a pending STK prompt, and the callback each outcome produces. */
final readonly class Scenarios
{
    /** action => [status, ResultCode, ResultDesc] */
    private const OUTCOMES = [
        'accept' => ['success', 0, 'The service request is processed successfully.'],
        'cancel' => ['cancelled', 1032, 'Request cancelled by user'],
        'timeout' => ['timeout', 1037, 'DS timeout user cannot be reached'],
        'insufficient' => ['insufficient_funds', 1, 'The balance is insufficient for the transaction'],
        'wrong_pin' => ['wrong_pin', 2001, 'The initiator information is invalid.'],
    ];

    public function __construct(
        private Store $store,
        private Clock $clock,
        private MockConfig $config,
    ) {
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::OUTCOMES);
    }

    public function maxAttempts(): int
    {
        return \count($this->config->retryDelays) + 1;
    }

    /**
     * Resolve a pending prompt. Returns null if it was not pending (already answered).
     */
    public function resolveStk(int $id, string $action, bool $sendCallback = true): ?Txn
    {
        $outcome = self::OUTCOMES[$action] ?? null;
        if ($outcome === null) {
            throw new \InvalidArgumentException('Unknown action ' . $action);
        }
        [$status, $code, $desc] = $outcome;
        $now = $this->now();

        $receipt = $code === 0 ? Ids::receipt() : '';
        $won = $this->store->resolvePending($id, [
            'status' => $status,
            'result_code' => $code,
            'result_desc' => $desc,
            'receipt' => $receipt,
            'resolved_at' => $now,
            'callback_withheld' => $sendCallback ? 0 : 1,
        ]);
        if (!$won) {
            return null;
        }
        $txn = $this->store->transaction($id);
        if ($txn !== null && $sendCallback) {
            $this->enqueueStkCallback($txn);
        }

        return $txn;
    }

    /** Queue (or re-queue) the STK callback for an already resolved transaction. */
    public function enqueueStkCallback(Txn $txn): void
    {
        if ($txn->callbackUrl === '') {
            return;
        }
        $payload = CallbackFactory::encode(CallbackFactory::stk($txn, $this->clock->now()));
        $this->store->addDelivery($txn->id, 'STK callback', $txn->callbackUrl, $payload, $this->maxAttempts(), $this->now());
        $this->store->updateTransaction($txn->id, ['callback_withheld' => 0]);
    }

    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
