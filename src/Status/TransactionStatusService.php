<?php

declare(strict_types=1);

namespace Daraja\Status;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\ValidationException;
use Daraja\Http\ApiClient;
use Daraja\Support\AsyncAck;

/**
 * Transaction Status: ask Daraja what happened to a receipt. Use it to reconcile payments whose
 * callback never arrived. Asynchronous: the answer is posted to your ResultURL.
 */
final readonly class TransactionStatusService
{
    public const PATH = '/mpesa/transactionstatus/v1/query';

    public function __construct(private DarajaConfig $config, private ApiClient $api)
    {
    }

    public function query(
        string $transactionId,
        string $resultUrl,
        string $timeoutUrl,
        IdentifierType $identifierType = IdentifierType::ShortCode,
        string $remarks = 'Status check',
        string $occasion = '',
    ): AsyncAck {
        if (preg_match('/^[A-Za-z0-9]{6,32}$/', $transactionId) !== 1) {
            throw new ValidationException('TransactionID (the M-Pesa receipt number) looks malformed.');
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
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => $transactionId,
            'PartyA' => (int) $this->config->shortCode,
            'IdentifierType' => $identifierType->value,
            'ResultURL' => $resultUrl,
            'QueueTimeOutURL' => $timeoutUrl,
            'Remarks' => $remarks,
            'Occasion' => $occasion,
        ]);
        ApiClient::assertAccepted($response);

        return AsyncAck::fromPayload($response);
    }
}
