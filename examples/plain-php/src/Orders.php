<?php

declare(strict_types=1);

namespace Shop;

use Daraja\Callback\StkCallback;
use Daraja\Safety\ExpectedPayment;
use Daraja\Stk\StkOutcome;

/** Order storage for the example shop. Plain PDO, parameterised SQL only. */
final class Orders
{
    public const CATALOGUE = [
        'maize-flour' => ['Maize flour 2 kg', 180],
        'cooking-oil' => ['Cooking oil 1 L', 320],
        'rice' => ['Pishori rice 5 kg', 950],
    ];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(string $itemKey, string $phone): array
    {
        [$name, $price] = self::CATALOGUE[$itemKey];
        $ref = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $this->pdo->prepare('INSERT INTO orders (ref, item, amount_kes, phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$ref, $name, $price, $phone, 'pending', gmdate('c')]);

        return $this->byRef($ref) ?? throw new \LogicException('Order vanished');
    }

    /** @return array<string, mixed>|null */
    public function byRef(string $ref): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE ref = ?');
        $stmt->execute([$ref]);
        $row = $stmt->fetch();

        return \is_array($row) ? $row : null;
    }

    public function attachCheckoutId(string $ref, string $checkoutRequestId): void
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET checkout_request_id = ? WHERE ref = ?');
        $stmt->execute([$checkoutRequestId, $ref]);
    }

    public function markFailed(string $ref, string $detail): void
    {
        $stmt = $this->pdo->prepare("UPDATE orders SET status = 'failed', detail = ? WHERE ref = ? AND status = 'pending'");
        $stmt->execute([$detail, $ref]);
    }

    /** What we expect Daraja to confirm, looked up by the id Daraja gave us when the prompt was sent. */
    public function expectation(string $checkoutRequestId): ?ExpectedPayment
    {
        $stmt = $this->pdo->prepare('SELECT amount_kes, phone FROM orders WHERE checkout_request_id = ?');
        $stmt->execute([$checkoutRequestId]);
        $row = $stmt->fetch();
        if (!\is_array($row) || !\is_int($row['amount_kes']) || !\is_string($row['phone'])) {
            return null;
        }

        return ExpectedPayment::kes($row['amount_kes'], $row['phone']);
    }

    /**
     * Apply an STK result to the order. Safe to call twice: every UPDATE is guarded by the current status,
     * and mpesa_receipt is UNIQUE, so even if the idempotency store were bypassed the money could not be credited twice.
     */
    public function applyCallback(StkCallback $callback): void
    {
        if ($callback->isSuccessful()) {
            $stmt = $this->pdo->prepare(
                "UPDATE orders SET status = 'paid', mpesa_receipt = ?, paid_via = 'callback', detail = ''
                 WHERE checkout_request_id = ? AND status IN ('pending', 'failed')",
            );
            $stmt->execute([$callback->mpesaReceiptNumber, $callback->checkoutRequestId]);
            // A payment that was already settled by reconciliation has no receipt yet. Fill it in now.
            $fill = $this->pdo->prepare(
                "UPDATE orders SET mpesa_receipt = ? WHERE checkout_request_id = ? AND status = 'paid' AND mpesa_receipt IS NULL",
            );
            $fill->execute([$callback->mpesaReceiptNumber, $callback->checkoutRequestId]);

            return;
        }

        $label = match ($callback->outcome()) {
            StkOutcome::Cancelled => 'cancelled',
            StkOutcome::TimedOut => 'timed_out',
            StkOutcome::InsufficientFunds => 'insufficient_funds',
            StkOutcome::WrongPin => 'wrong_pin',
            default => 'failed',
        };
        $stmt = $this->pdo->prepare("UPDATE orders SET status = ?, detail = ? WHERE checkout_request_id = ? AND status = 'pending'");
        $stmt->execute([$label, $callback->resultDesc, $callback->checkoutRequestId]);
    }

    /**
     * Settle an order from an STK *query* answer when the callback never arrived.
     * The query response carries no receipt number, so the order is marked paid without one until a callback
     * or a Transaction Status check fills it in. For high-value goods, wait for the receipt before releasing them.
     */
    public function applyQuery(string $ref, bool $success, string $label, string $detail): void
    {
        if ($success) {
            $stmt = $this->pdo->prepare("UPDATE orders SET status = 'paid', paid_via = 'stk_query' WHERE ref = ? AND status = 'pending'");
            $stmt->execute([$ref]);

            return;
        }
        $stmt = $this->pdo->prepare("UPDATE orders SET status = ?, detail = ? WHERE ref = ? AND status = 'pending'");
        $stmt->execute([$label, $detail, $ref]);
    }
}
