<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Support\Json;

/**
 * Builds the callback bodies the mock POSTs to your server. The shapes follow Safaricom's public
 * documentation; they were not recorded from live traffic.
 */
final class CallbackFactory
{
    /** @return array<string, mixed> */
    public static function stk(Txn $txn, \DateTimeImmutable $now): array
    {
        $callback = [
            'MerchantRequestID' => $txn->merchantRequestId,
            'CheckoutRequestID' => $txn->checkoutRequestId,
            'ResultCode' => $txn->resultCode ?? 0,
            'ResultDesc' => $txn->resultDesc,
        ];
        if (($txn->resultCode ?? -1) === 0) {
            $callback['CallbackMetadata'] = [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => (float) ($txn->amountCents / 100)],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => $txn->receipt],
                    ['Name' => 'TransactionDate', 'Value' => (int) $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => (int) $txn->msisdn],
                ],
            ];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }

    /** @return array<string, mixed> */
    public static function c2b(Txn $txn, \DateTimeImmutable $now): array
    {
        return [
            'TransactionType' => 'Pay Bill',
            'TransID' => $txn->receipt,
            'TransTime' => $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('YmdHis'),
            'TransAmount' => number_format($txn->amountCents / 100, 2, '.', ''),
            'BusinessShortCode' => $txn->shortCode,
            'BillRefNumber' => $txn->accountReference,
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '49197.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => $txn->msisdn,
            'FirstName' => 'Test',
            'MiddleName' => '',
            'LastName' => 'Customer',
        ];
    }

    /** @return array<string, mixed> */
    public static function b2cResult(Txn $txn, \DateTimeImmutable $now, int $resultCode = 0, string $resultDesc = 'The service request is processed successfully.'): array
    {
        $result = [
            'ResultType' => 0,
            'ResultCode' => $resultCode,
            'ResultDesc' => $resultDesc,
            'OriginatorConversationID' => $txn->originatorConversationId,
            'ConversationID' => $txn->conversationId,
            'TransactionID' => $resultCode === 0 ? $txn->receipt : 'MOCK' . substr($txn->conversationId, -6),
        ];
        if ($resultCode === 0) {
            $result['ResultParameters'] = ['ResultParameter' => [
                ['Key' => 'TransactionAmount', 'Value' => (float) ($txn->amountCents / 100)],
                ['Key' => 'TransactionReceipt', 'Value' => $txn->receipt],
                ['Key' => 'B2CRecipientIsRegisteredCustomer', 'Value' => 'Y'],
                ['Key' => 'ReceiverPartyPublicName', 'Value' => $txn->msisdn . ' - Test Customer'],
                ['Key' => 'TransactionCompletedDateTime', 'Value' => $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('d.m.Y H:i:s')],
                ['Key' => 'B2CUtilityAccountAvailableFunds', 'Value' => 10116.0],
                ['Key' => 'B2CWorkingAccountAvailableFunds', 'Value' => 900000.0],
            ]];
        }

        return ['Result' => $result];
    }

    /**
     * Transaction Status result for a known receipt.
     *
     * @return array<string, mixed>
     */
    public static function statusResult(string $originatorConversationId, string $conversationId, ?Txn $found, \DateTimeImmutable $now): array
    {
        if ($found === null) {
            return ['Result' => [
                'ResultType' => 0,
                'ResultCode' => 2001,
                'ResultDesc' => 'No transaction with that receipt exists in this mock.',
                'OriginatorConversationID' => $originatorConversationId,
                'ConversationID' => $conversationId,
                'TransactionID' => '',
            ]];
        }

        return ['Result' => [
            'ResultType' => 0,
            'ResultCode' => 0,
            'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => $originatorConversationId,
            'ConversationID' => $conversationId,
            'TransactionID' => Ids::receipt(),
            'ResultParameters' => ['ResultParameter' => [
                ['Key' => 'ReceiptNo', 'Value' => $found->receipt],
                ['Key' => 'TransactionStatus', 'Value' => $found->status === 'reversed' ? 'Reversed' : 'Completed'],
                ['Key' => 'Amount', 'Value' => (float) ($found->amountCents / 100)],
                ['Key' => 'DebitPartyName', 'Value' => $found->msisdn . ' - Test Customer'],
                ['Key' => 'CreditPartyName', 'Value' => $found->shortCode . ' - Mock Merchant'],
                ['Key' => 'FinalisedTime', 'Value' => (int) $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('YmdHis')],
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    public static function reversalResult(string $originatorConversationId, string $conversationId, bool $ok, string $transactionId): array
    {
        return ['Result' => [
            'ResultType' => 0,
            'ResultCode' => $ok ? 0 : 2001,
            'ResultDesc' => $ok ? 'The service request is processed successfully.' : 'No reversible transaction with that receipt exists in this mock.',
            'OriginatorConversationID' => $originatorConversationId,
            'ConversationID' => $conversationId,
            'TransactionID' => $ok ? Ids::receipt() : '',
            'ReferenceData' => ['ReferenceItem' => ['Key' => 'OriginalTransactionID', 'Value' => $transactionId]],
        ]];
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return Json::encode($payload);
    }
}
