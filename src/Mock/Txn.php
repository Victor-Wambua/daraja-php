<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** One simulated payment: an STK prompt, a C2B payment or a B2C payout. */
final readonly class Txn
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $status,
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public string $conversationId,
        public string $originatorConversationId,
        public string $shortCode,
        public string $partyB,
        public string $txnType,
        public int $amountCents,
        public string $msisdn,
        public string $accountReference,
        public string $description,
        public string $callbackUrl,
        public ?int $resultCode,
        public string $resultDesc,
        public string $receipt,
        public float $createdAt,
        public ?float $resolvedAt,
        public ?float $expiresAt,
        public bool $callbackWithheld,
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
            kind: $s('kind'),
            status: $s('status'),
            merchantRequestId: $s('merchant_request_id'),
            checkoutRequestId: $s('checkout_request_id'),
            conversationId: $s('conversation_id'),
            originatorConversationId: $s('originator_conversation_id'),
            shortCode: $s('shortcode'),
            partyB: $s('party_b'),
            txnType: $s('txn_type'),
            amountCents: $i('amount_cents'),
            msisdn: $s('msisdn'),
            accountReference: $s('account_reference'),
            description: $s('description'),
            callbackUrl: $s('callback_url'),
            resultCode: \is_numeric($row['result_code'] ?? null) ? (int) $row['result_code'] : null,
            resultDesc: $s('result_desc'),
            receipt: $s('receipt'),
            createdAt: $f('created_at') ?? 0.0,
            resolvedAt: $f('resolved_at'),
            expiresAt: $f('expires_at'),
            callbackWithheld: $i('callback_withheld') === 1,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'merchantRequestId' => $this->merchantRequestId,
            'checkoutRequestId' => $this->checkoutRequestId,
            'conversationId' => $this->conversationId,
            'shortCode' => $this->shortCode,
            'txnType' => $this->txnType,
            'amount' => $this->amountCents / 100,
            'msisdn' => $this->msisdn,
            'accountReference' => $this->accountReference,
            'description' => $this->description,
            'callbackUrl' => $this->callbackUrl,
            'resultCode' => $this->resultCode,
            'resultDesc' => $this->resultDesc,
            'receipt' => $this->receipt,
            'createdAt' => $this->createdAt,
            'resolvedAt' => $this->resolvedAt,
            'expiresAt' => $this->expiresAt,
            'callbackWithheld' => $this->callbackWithheld,
        ];
    }
}
