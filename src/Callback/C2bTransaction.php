<?php

declare(strict_types=1);

namespace Daraja\Callback;

use Daraja\Support\Money;

/**
 * A C2B validation or confirmation payload (both share one shape).
 * The MSISDN is kept as sent: Safaricom may mask or hash it, so do not rely on it being a phone number.
 */
final readonly class C2bTransaction
{
    public function __construct(
        public string $transactionType,
        public string $transId,
        public \DateTimeImmutable $transTime,
        public Money $amount,
        public string $businessShortCode,
        public string $billRefNumber,
        public string $invoiceNumber,
        public ?Money $orgAccountBalance,
        public string $thirdPartyTransId,
        public string $msisdn,
        public string $firstName,
        public string $middleName,
        public string $lastName,
    ) {
    }
}
