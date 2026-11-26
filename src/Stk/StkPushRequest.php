<?php

declare(strict_types=1);

namespace Daraja\Stk;

use Daraja\Exception\ValidationException;
use Daraja\Support\Msisdn;

/**
 * One STK Push (Lipa na M-Pesa Online) request. Validated on construction, immutable afterwards.
 */
final readonly class StkPushRequest
{
    public Msisdn $phoneNumber;

    /**
     * @param int         $amount           Whole Kenyan shillings, at least 1
     * @param string      $phoneNumber      07XXXXXXXX, 01XXXXXXXX, +254... or 254...; normalised to 254XXXXXXXXX
     * @param string      $callbackUrl      Where Daraja will POST the result
     * @param string      $accountReference Shown to the customer (short; limit is set in DarajaConfig)
     * @param string      $transactionDesc  Free text (short; limit is set in DarajaConfig)
     * @param string|null $partyB           Defaults to the short code. For till payments pass the till number.
     */
    public function __construct(
        public int $amount,
        string $phoneNumber,
        public string $callbackUrl,
        public string $accountReference,
        public string $transactionDesc,
        public TransactionType $type = TransactionType::PayBill,
        public ?string $partyB = null,
    ) {
        if ($amount < 1) {
            throw new ValidationException('Amount must be a whole number of shillings, at least 1.');
        }
        $this->phoneNumber = Msisdn::parse($phoneNumber);
        if ($accountReference === '' || trim($transactionDesc) === '') {
            throw new ValidationException('Account reference and transaction description are required.');
        }
        if (filter_var($callbackUrl, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $callbackUrl) !== 1) {
            throw new ValidationException('Callback URL must be an absolute http(s) URL.');
        }
        if ($partyB !== null && preg_match('/^\d{5,7}$/', $partyB) !== 1) {
            throw new ValidationException('PartyB must be a 5 to 7 digit short code or till number.');
        }
    }
}
