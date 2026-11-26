<?php

declare(strict_types=1);

namespace Daraja\B2c;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\ValidationException;
use Daraja\Http\ApiClient;
use Daraja\Support\AsyncAck;

/** Business-to-Customer payouts. Asynchronous: the ack means "accepted", the Result callback is the outcome. */
final readonly class B2cService
{
    public const PATH = '/mpesa/b2c/v1/paymentrequest';

    public function __construct(private DarajaConfig $config, private ApiClient $api)
    {
    }

    public function pay(B2cRequest $request): AsyncAck
    {
        $initiator = $this->config->requireInitiator();
        if ($this->config->isProduction()) {
            foreach ([$request->resultUrl, $request->timeoutUrl] as $url) {
                if (preg_match('#^https://#i', $url) !== 1) {
                    throw new ValidationException('Production result and timeout URLs must use https.');
                }
            }
        }

        $response = $this->api->post(self::PATH, [
            'OriginatorConversationID' => $request->originatorConversationId,
            'InitiatorName' => $initiator['name'],
            'SecurityCredential' => $initiator['credential'],
            'CommandID' => $request->command->value,
            'Amount' => $request->amount,
            'PartyA' => (int) $this->config->shortCode,
            'PartyB' => (int) $request->phoneNumber->value,
            'Remarks' => $request->remarks,
            'QueueTimeOutURL' => $request->timeoutUrl,
            'ResultURL' => $request->resultUrl,
            'Occasion' => $request->occasion,
        ]);
        ApiClient::assertAccepted($response);

        return AsyncAck::fromPayload($response);
    }
}
