<?php

declare(strict_types=1);

namespace Daraja\Stk;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\ApiException;
use Daraja\Exception\ConfigurationException;
use Daraja\Exception\ValidationException;
use Daraja\Http\ApiClient;
use Daraja\Support\Clock;

/** Lipa na M-Pesa Online (STK Push): initiate and query. */
final readonly class StkService
{
    public const PUSH_PATH = '/mpesa/stkpush/v1/processrequest';
    public const QUERY_PATH = '/mpesa/stkpushquery/v1/query';

    /**
     * Error code Daraja has been reported to return from the query endpoint while the customer
     * has not yet answered ("The transaction is being processed"). Treated as "pending", not as a failure.
     * Taken from developer reports, not from a recorded live call; verify against your sandbox.
     */
    public const PENDING_ERROR_CODE = '500.001.1001';

    public function __construct(
        private DarajaConfig $config,
        private ApiClient $api,
        private Clock $clock,
    ) {
    }

    public function push(StkPushRequest $request): StkPushResponse
    {
        if (mb_strlen($request->accountReference) > $this->config->accountReferenceMaxLength) {
            throw new ValidationException(\sprintf('Account reference is longer than %d characters.', $this->config->accountReferenceMaxLength));
        }
        if (mb_strlen($request->transactionDesc) > $this->config->transactionDescMaxLength) {
            throw new ValidationException(\sprintf('Transaction description is longer than %d characters.', $this->config->transactionDescMaxLength));
        }
        if ($this->config->isProduction() && preg_match('#^https://#i', $request->callbackUrl) !== 1) {
            throw new ValidationException('Production callback URLs must use https.');
        }

        $timestamp = StkPassword::timestamp($this->clock->now());
        $response = $this->api->post(self::PUSH_PATH, [
            'BusinessShortCode' => (int) $this->config->shortCode,
            'Password' => StkPassword::generate($this->config->shortCode, $this->requirePassKey(), $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $request->type->value,
            'Amount' => $request->amount,
            'PartyA' => (int) $request->phoneNumber->value,
            'PartyB' => (int) ($request->partyB ?? $this->config->shortCode),
            'PhoneNumber' => (int) $request->phoneNumber->value,
            'CallBackURL' => $request->callbackUrl,
            'AccountReference' => $request->accountReference,
            'TransactionDesc' => $request->transactionDesc,
        ]);
        ApiClient::assertAccepted($response);

        return new StkPushResponse(
            merchantRequestId: $response->string('MerchantRequestID'),
            checkoutRequestId: $response->string('CheckoutRequestID'),
            responseCode: $response->string('ResponseCode'),
            responseDescription: $response->looseString('ResponseDescription'),
            customerMessage: $response->looseString('CustomerMessage'),
        );
    }

    public function query(string $checkoutRequestId): StkQueryResult
    {
        if ($checkoutRequestId === '' || preg_match('/^[\w\-]{1,100}$/', $checkoutRequestId) !== 1) {
            throw new ValidationException('CheckoutRequestID looks malformed.');
        }

        $timestamp = StkPassword::timestamp($this->clock->now());
        try {
            $response = $this->api->post(self::QUERY_PATH, [
                'BusinessShortCode' => (int) $this->config->shortCode,
                'Password' => StkPassword::generate($this->config->shortCode, $this->requirePassKey(), $timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);
        } catch (ApiException $e) {
            if ($e->errorCode === self::PENDING_ERROR_CODE) {
                return new StkQueryResult($checkoutRequestId, pending: true);
            }
            throw $e;
        }

        // ResultCode is a string ("0") here but an integer in the callback; Payload::int accepts both.
        return new StkQueryResult(
            checkoutRequestId: $response->optionalString('CheckoutRequestID') ?? $checkoutRequestId,
            pending: false,
            resultCode: $response->int('ResultCode'),
            resultDesc: $response->looseString('ResultDesc'),
            merchantRequestId: $response->optionalString('MerchantRequestID'),
        );
    }

    private function requirePassKey(): string
    {
        if ($this->config->passKey === '') {
            throw new ConfigurationException('A passkey is required for STK Push. Set DarajaConfig::$passKey.');
        }

        return $this->config->passKey;
    }
}
