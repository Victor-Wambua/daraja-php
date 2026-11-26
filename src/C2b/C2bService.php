<?php

declare(strict_types=1);

namespace Daraja\C2b;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\ConfigurationException;
use Daraja\Exception\ValidationException;
use Daraja\Http\ApiClient;
use Daraja\Support\Msisdn;

/** Customer-to-Business: register your validation/confirmation URLs and, in sandbox, simulate a payment. */
final readonly class C2bService
{
    public function __construct(private DarajaConfig $config, private ApiClient $api)
    {
    }

    /**
     * Register the URLs Daraja calls when a customer pays your paybill/till.
     *
     * Safaricom restricts what the URLs may contain (their docs warn against certain brand words in the path);
     * check the current Daraja documentation if registration is refused. Production requires https.
     *
     * @param int $version 1 or 2: selects /mpesa/c2b/v{n}/registerurl
     *
     * @return array{originatorConversationId: string, responseCode: string, responseDescription: string}
     */
    public function registerUrls(
        string $confirmationUrl,
        string $validationUrl,
        C2bResponseType $responseType = C2bResponseType::Completed,
        int $version = 2,
    ): array {
        if (!\in_array($version, [1, 2], true)) {
            throw new ValidationException('C2B register URL version must be 1 or 2.');
        }
        foreach ([$confirmationUrl, $validationUrl] as $url) {
            $pattern = $this->config->isProduction() ? '#^https://#i' : '#^https?://#i';
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match($pattern, $url) !== 1) {
                throw new ValidationException($this->config->isProduction() ? 'C2B URLs must be https URLs in production.' : 'C2B URLs must be absolute http(s) URLs.');
            }
        }

        $response = $this->api->post(\sprintf('/mpesa/c2b/v%d/registerurl', $version), [
            'ShortCode' => (int) $this->config->shortCode,
            'ResponseType' => $responseType->value,
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL' => $validationUrl,
        ]);
        ApiClient::assertAccepted($response);

        // Daraja's own field name is spelled "OriginatorCoversationID" in its documentation.
        return [
            'originatorConversationId' => $response->optionalString('OriginatorCoversationID') ?? $response->looseString('OriginatorConversationID'),
            'responseCode' => $response->string('ResponseCode'),
            'responseDescription' => $response->looseString('ResponseDescription'),
        ];
    }

    /**
     * Sandbox only: ask Daraja to simulate a customer payment to your paybill.
     *
     * @return array{conversationId: string, originatorConversationId: string, responseDescription: string}
     */
    public function simulate(int $amount, string $msisdn, string $billRefNumber): array
    {
        if ($this->config->isProduction()) {
            throw new ConfigurationException('C2B simulate exists only in the sandbox.');
        }
        if ($amount < 1) {
            throw new ValidationException('Amount must be a whole number of shillings, at least 1.');
        }
        if ($billRefNumber === '' || mb_strlen($billRefNumber) > 20) {
            throw new ValidationException('BillRefNumber must be 1 to 20 characters.');
        }

        $response = $this->api->post('/mpesa/c2b/v1/simulate', [
            'ShortCode' => (int) $this->config->shortCode,
            'CommandID' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'Msisdn' => (int) Msisdn::parse($msisdn)->value,
            'BillRefNumber' => $billRefNumber,
        ]);

        return [
            'conversationId' => $response->looseString('ConversationID'),
            'originatorConversationId' => $response->optionalString('OriginatorCoversationID') ?? $response->looseString('OriginatorConversationID'),
            'responseDescription' => $response->looseString('ResponseDescription'),
        ];
    }
}
