<?php

declare(strict_types=1);

namespace Daraja\Callback;

use Daraja\Exception\InvalidCallbackPayloadException;
use Daraja\Exception\MalformedPayloadException;
use Daraja\Support\EatTime;
use Daraja\Support\Json;
use Daraja\Support\Money;
use Daraja\Support\Msisdn;
use Daraja\Support\Payload;

/**
 * Turns raw callback bodies into typed objects, or throws InvalidCallbackPayloadException.
 * Nothing is half-parsed: a payload either matches the documented shape or is rejected.
 */
final class CallbackParser
{
    /** Callbacks are small. Anything bigger is not from Daraja. */
    public const MAX_BODY_BYTES = 65_536;

    public static function parseStk(string $rawBody): StkCallback
    {
        try {
            $callback = self::root($rawBody)->object('Body')->object('stkCallback');

            $resultCode = $callback->int('ResultCode');
            $items = [];
            $metadata = $callback->optionalObject('CallbackMetadata');
            if ($metadata !== null) {
                foreach ($metadata->objects('Item') as $item) {
                    $items[$item->string('Name')] = $item->scalar('Value');
                }
            }

            $amount = null;
            $receipt = null;
            $date = null;
            $phone = null;
            if ($resultCode === 0) {
                if ($metadata === null) {
                    throw new MalformedPayloadException('A successful callback must carry CallbackMetadata.');
                }
                $rawAmount = $items['Amount'] ?? null;
                if ($rawAmount === null || \is_bool($rawAmount)) {
                    throw new MalformedPayloadException('A successful callback must carry an Amount item.');
                }
                $amount = Money::fromWire($rawAmount);
                if ($amount->cents < 1) {
                    throw new MalformedPayloadException('Amount must be positive.');
                }
                $receipt = self::receipt($items['MpesaReceiptNumber'] ?? null);
                $rawDate = $items['TransactionDate'] ?? null;
                if (!\is_int($rawDate) && !\is_string($rawDate)) {
                    throw new MalformedPayloadException('A successful callback must carry a TransactionDate item.');
                }
                $date = EatTime::parse((string) $rawDate);
            }
            if (isset($items['PhoneNumber']) && (\is_int($items['PhoneNumber']) || \is_string($items['PhoneNumber']))) {
                $phone = Msisdn::tryParse((string) $items['PhoneNumber']);
            }

            return new StkCallback(
                merchantRequestId: $callback->string('MerchantRequestID'),
                checkoutRequestId: $callback->string('CheckoutRequestID'),
                resultCode: $resultCode,
                resultDesc: $callback->looseString('ResultDesc'),
                amount: $amount,
                mpesaReceiptNumber: $receipt,
                transactionDate: $date,
                phoneNumber: $phone,
                metadata: $items,
            );
        } catch (MalformedPayloadException $e) {
            throw self::wrap($e);
        }
    }

    public static function parseC2b(string $rawBody): C2bTransaction
    {
        try {
            $p = self::root($rawBody);
            $balance = $p->has('OrgAccountBalance') ? self::balance($p->string('OrgAccountBalance')) : null;
            $amount = Money::fromWire($p->string('TransAmount'));
            if ($amount->cents < 1) {
                throw new MalformedPayloadException('TransAmount must be positive.');
            }

            return new C2bTransaction(
                transactionType: $p->looseString('TransactionType'),
                transId: self::receipt($p->scalar('TransID')),
                transTime: EatTime::parse($p->string('TransTime')),
                amount: $amount,
                businessShortCode: $p->string('BusinessShortCode'),
                billRefNumber: $p->looseString('BillRefNumber'),
                invoiceNumber: $p->looseString('InvoiceNumber'),
                orgAccountBalance: $balance,
                thirdPartyTransId: $p->looseString('ThirdPartyTransID'),
                msisdn: $p->string('MSISDN'),
                firstName: $p->looseString('FirstName'),
                middleName: $p->looseString('MiddleName'),
                lastName: $p->looseString('LastName'),
            );
        } catch (MalformedPayloadException $e) {
            throw self::wrap($e);
        }
    }

    public static function parseResult(string $rawBody): ResultCallback
    {
        try {
            $result = self::root($rawBody)->object('Result');
            $parameters = [];
            $block = $result->optionalObject('ResultParameters');
            if ($block !== null) {
                foreach ($block->objects('ResultParameter') as $param) {
                    $parameters[$param->string('Key')] = $param->scalar('Value');
                }
            }

            return new ResultCallback(
                resultCode: $result->int('ResultCode'),
                resultDesc: $result->looseString('ResultDesc'),
                originatorConversationId: $result->string('OriginatorConversationID'),
                conversationId: $result->string('ConversationID'),
                transactionId: $result->looseString('TransactionID'),
                resultType: $result->optionalInt('ResultType'),
                parameters: $parameters,
            );
        } catch (MalformedPayloadException $e) {
            throw self::wrap($e);
        }
    }

    private static function root(string $rawBody): Payload
    {
        if ($rawBody === '' || \strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new InvalidCallbackPayloadException('Callback body is empty or too large.');
        }
        try {
            return Payload::root(Json::decodeObject($rawBody));
        } catch (\JsonException $e) {
            throw new InvalidCallbackPayloadException('Callback body is not a JSON object.', 0, $e);
        }
    }

    private static function receipt(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/^[A-Za-z0-9]{6,32}$/', $value) !== 1) {
            throw new MalformedPayloadException('Receipt / transaction id is missing or has an unexpected format.');
        }

        return $value;
    }

    private static function balance(string $value): ?Money
    {
        // Balances arrive as "49197.00" or sometimes as "Working Account|KES|700000.00|..."; only the plain form is parsed.
        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) === 1 ? Money::fromWire($value) : null;
    }

    private static function wrap(MalformedPayloadException $e): InvalidCallbackPayloadException
    {
        return $e instanceof InvalidCallbackPayloadException ? $e : new InvalidCallbackPayloadException($e->getMessage(), 0, $e);
    }
}
