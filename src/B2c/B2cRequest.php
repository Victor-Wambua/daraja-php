<?php

declare(strict_types=1);

namespace Daraja\B2c;

use Daraja\Exception\ValidationException;
use Daraja\Support\Msisdn;

/** A business-to-customer payout. */
final readonly class B2cRequest
{
    public Msisdn $phoneNumber;
    public string $originatorConversationId;

    /**
     * @param int         $amount                   Whole shillings, at least 1
     * @param string|null $originatorConversationId Your own unique id for this payout; generated when omitted.
     *                                              Store it: it is how you match the Result callback.
     */
    public function __construct(
        public int $amount,
        string $phoneNumber,
        public string $remarks,
        public string $resultUrl,
        public string $timeoutUrl,
        public B2cCommand $command = B2cCommand::BusinessPayment,
        public string $occasion = '',
        ?string $originatorConversationId = null,
    ) {
        if ($amount < 1) {
            throw new ValidationException('Amount must be a whole number of shillings, at least 1.');
        }
        $this->phoneNumber = Msisdn::parse($phoneNumber);
        if (trim($remarks) === '') {
            throw new ValidationException('Remarks are required.');
        }
        foreach ([$resultUrl, $timeoutUrl] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
                throw new ValidationException('Result and timeout URLs must be absolute http(s) URLs.');
            }
        }
        $this->originatorConversationId = $originatorConversationId ?? self::uuid();
        if (preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $this->originatorConversationId) !== 1) {
            throw new ValidationException('OriginatorConversationID must be 8 to 64 characters of letters, digits, - _ .');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
