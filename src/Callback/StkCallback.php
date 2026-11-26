<?php

declare(strict_types=1);

namespace Daraja\Callback;

use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkResultCode;
use Daraja\Support\Money;
use Daraja\Support\Msisdn;

/**
 * A parsed STK Push result. On success (resultCode 0) the amount, receipt number and transaction
 * date are guaranteed to be present: the parser refuses a "success" without them.
 */
final readonly class StkCallback
{
    /**
     * @param array<string, int|float|string|bool|null> $metadata every CallbackMetadata item by name
     */
    public function __construct(
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public int $resultCode,
        public string $resultDesc,
        public ?Money $amount = null,
        public ?string $mpesaReceiptNumber = null,
        public ?\DateTimeImmutable $transactionDate = null,
        public ?Msisdn $phoneNumber = null,
        public array $metadata = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->resultCode === StkResultCode::Success->value;
    }

    /** The documented code, or null when Safaricom sent one this library does not list. */
    public function result(): ?StkResultCode
    {
        return StkResultCode::tryFromCode($this->resultCode);
    }

    public function outcome(): StkOutcome
    {
        return $this->result()?->outcome() ?? StkOutcome::Failed;
    }
}
