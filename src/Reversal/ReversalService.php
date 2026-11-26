<?php

declare(strict_types=1);

namespace Daraja\Reversal;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\ValidationException;
use Daraja\Http\ApiClient;
use Daraja\Support\AsyncAck;

/** Reverse a completed C2B transaction. Asynchronous: the outcome is posted to your ResultURL. */
final readonly class ReversalService
{
    public const PATH = '/mpesa/reversal/v1/request';

    public function __construct(private DarajaConfig $config, private ApiClient $api)
    {
    }

    /**
     * @param string $receiverIdentifierType Daraja's field is spelled "RecieverIdentifierType". Documentation
     *                                       has shown different values over time ("11" for a paybill account in
     *                                       current docs, "4" in older ones), so it is a parameter, not a guess.
     */
    public function reverse(
        string $transactionId,
        int $amount,
        string $resultUrl,
        string $timeoutUrl,
        string $remarks = 'Reversal',
        string $occasion = '',
        string $receiverIdentifierType = '11',
    ): AsyncAck {
        if (preg_match('/^[A-Za-z0-9]{6,32}$/', $transactionId) !== 1) {
            throw new ValidationException('TransactionID (the M-Pesa receipt number) looks malformed.');
        }
        if ($amount < 1) {
            throw new ValidationException('Amount must be a whole number of shillings, at least 1.');
        }
        foreach ([$resultUrl, $timeoutUrl] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
                throw new ValidationException('Result and timeout URLs must be absolute http(s) URLs.');
            }
        }
        $initiator = $this->config->requireInitiator();

        $response = $this->api->post(self::PATH, [
            'Initiator' => $initiator['name'],
            'SecurityCredential' => $initiator['credential'],
            'CommandID' => 'TransactionReversal',
            'TransactionID' => $transactionId,
            'Amount' => $amount,
            'ReceiverParty' => (int) $this->config->shortCode,
            'RecieverIdentifierType' => $receiverIdentifierType,
            'ResultURL' => $resultUrl,
            'QueueTimeOutURL' => $timeoutUrl,
            'Remarks' => $remarks,
            'Occasion' => $occasion,
        ]);
        ApiClient::assertAccepted($response);

        return AsyncAck::fromPayload($response);
    }
}
