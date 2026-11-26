<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Support\Money;
use Daraja\Support\Msisdn;

/**
 * What your own records say a payment should look like. Build it from the order you created when you
 * sent the request, then let the library compare the callback against it.
 */
final readonly class ExpectedPayment
{
    public function __construct(
        public Money $amount,
        public ?Msisdn $phoneNumber = null,
        public ?string $accountReference = null,
    ) {
    }

    public static function kes(int $shillings, ?string $phone = null, ?string $accountReference = null): self
    {
        return new self(Money::kes($shillings), $phone === null ? null : Msisdn::parse($phone), $accountReference);
    }
}
