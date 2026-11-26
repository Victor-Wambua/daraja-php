<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Callback;

use Daraja\Callback\CallbackParser;
use Daraja\Exception\InvalidCallbackPayloadException;
use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkResultCode;
use Daraja\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallbackParserTest extends TestCase
{
    public function testParsesASuccessfulStkCallback(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_success'));
        self::assertTrue($cb->isSuccessful());
        self::assertSame('29115-34620561-1', $cb->merchantRequestId);
        self::assertSame('ws_CO_191220191020363925', $cb->checkoutRequestId);
        self::assertSame(StkResultCode::Success, $cb->result());
        self::assertSame(StkOutcome::Success, $cb->outcome());
        self::assertNotNull($cb->amount);
        self::assertSame(100, $cb->amount->cents);
        self::assertSame('NLJ7RT61SV', $cb->mpesaReceiptNumber);
        self::assertNotNull($cb->transactionDate);
        self::assertSame('2019-12-19T10:21:15+03:00', $cb->transactionDate->format('c'));
        self::assertNotNull($cb->phoneNumber);
        self::assertSame('254700000001', $cb->phoneNumber->value);
        self::assertSame('NLJ7RT61SV', $cb->metadata['MpesaReceiptNumber']);
    }

    public function testParsesACancelledCallbackWithoutMetadata(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_cancelled'));
        self::assertFalse($cb->isSuccessful());
        self::assertSame(1032, $cb->resultCode);
        self::assertSame(StkResultCode::CancelledByUser, $cb->result());
        self::assertSame(StkOutcome::Cancelled, $cb->outcome());
        self::assertNull($cb->amount);
        self::assertNull($cb->mpesaReceiptNumber);
        self::assertSame('Request cancelled by user', $cb->resultDesc);
    }

    /** @return iterable<string, array{int, StkOutcome}> */
    public static function failureCodes(): iterable
    {
        yield 'insufficient funds' => [1, StkOutcome::InsufficientFunds];
        yield 'cancelled' => [1032, StkOutcome::Cancelled];
        yield 'timeout' => [1037, StkOutcome::TimedOut];
        yield 'wrong pin' => [2001, StkOutcome::WrongPin];
        yield 'unlisted code' => [4242, StkOutcome::Failed];
    }

    #[DataProvider('failureCodes')]
    public function testMapsFailureCodes(int $code, StkOutcome $expected): void
    {
        $raw = str_replace('"ResultCode": 1032', '"ResultCode": ' . $code, Fixtures::raw('stk_callback_cancelled'));
        $cb = CallbackParser::parseStk($raw);
        self::assertSame($code, $cb->resultCode);
        self::assertSame($expected, $cb->outcome());
    }

    public function testResultCodeAsStringIsAccepted(): void
    {
        $raw = str_replace('"ResultCode": 1032', '"ResultCode": "1032"', Fixtures::raw('stk_callback_cancelled'));
        self::assertSame(1032, CallbackParser::parseStk($raw)->resultCode);
    }

    public function testAmountWithCentsIsKeptExactly(): void
    {
        $raw = str_replace('"Value": 1.00', '"Value": 10.5', Fixtures::raw('stk_callback_success'));
        $cb = CallbackParser::parseStk($raw);
        self::assertNotNull($cb->amount);
        self::assertSame(1050, $cb->amount->cents);
    }

    /** @return iterable<string, array{string}> */
    public static function badStkBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['not json'];
        yield 'json list' => ['[1,2,3]'];
        yield 'json scalar' => ['"hello"'];
        yield 'missing Body' => ['{"stkCallback":{}}'];
        yield 'missing stkCallback' => ['{"Body":{}}'];
        yield 'missing checkout id' => ['{"Body":{"stkCallback":{"MerchantRequestID":"1","ResultCode":1032,"ResultDesc":"x"}}}'];
        yield 'missing result code' => ['{"Body":{"stkCallback":{"MerchantRequestID":"1","CheckoutRequestID":"2","ResultDesc":"x"}}}'];
        yield 'non numeric result code' => ['{"Body":{"stkCallback":{"MerchantRequestID":"1","CheckoutRequestID":"2","ResultCode":"ok","ResultDesc":"x"}}}'];
        yield 'success without metadata' => ['{"Body":{"stkCallback":{"MerchantRequestID":"1","CheckoutRequestID":"2","ResultCode":0,"ResultDesc":"x"}}}'];
    }

    #[DataProvider('badStkBodies')]
    public function testRejectsMalformedStkBodies(string $raw): void
    {
        $this->expectException(InvalidCallbackPayloadException::class);
        CallbackParser::parseStk($raw);
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenSuccessItems(): iterable
    {
        yield 'no receipt' => ['"Name": "MpesaReceiptNumber", "Value": "NLJ7RT61SV"', '"Name": "Other", "Value": "x"'];
        yield 'receipt with spaces' => ['"Value": "NLJ7RT61SV"', '"Value": "NLJ 7RT"'];
        yield 'no amount' => ['"Name": "Amount", "Value": 1.00', '"Name": "Other", "Value": 1'];
        yield 'zero amount' => ['"Value": 1.00', '"Value": 0'];
        yield 'no date' => ['"Name": "TransactionDate", "Value": 20191219102115', '"Name": "Other", "Value": 1'];
        yield 'impossible date' => ['20191219102115', '20191399102115'];
    }

    #[DataProvider('brokenSuccessItems')]
    public function testSuccessWithoutTrustworthyDetailsIsRejected(string $find, string $replace): void
    {
        $raw = str_replace($find, $replace, Fixtures::raw('stk_callback_success'));
        self::assertNotSame($raw, Fixtures::raw('stk_callback_success'));
        $this->expectException(InvalidCallbackPayloadException::class);
        CallbackParser::parseStk($raw);
    }

    public function testOversizedBodiesAreRejected(): void
    {
        $this->expectException(InvalidCallbackPayloadException::class);
        CallbackParser::parseStk('{"x":"' . str_repeat('a', CallbackParser::MAX_BODY_BYTES) . '"}');
    }

    public function testParsesC2bConfirmation(): void
    {
        $tx = CallbackParser::parseC2b(Fixtures::raw('c2b_confirmation'));
        self::assertSame('RKTQDM7W6S', $tx->transId);
        self::assertSame(1000, $tx->amount->cents);
        self::assertSame('invoice008', $tx->billRefNumber);
        self::assertSame('600638', $tx->businessShortCode);
        self::assertSame('2019-11-22T06:38:45+03:00', $tx->transTime->format('c'));
        self::assertNotNull($tx->orgAccountBalance);
        self::assertSame(4919700, $tx->orgAccountBalance->cents);
        self::assertSame('254700000001', $tx->msisdn);
        self::assertSame('Customer', $tx->lastName);
    }

    public function testC2bKeepsAMaskedMsisdnAsSent(): void
    {
        $raw = str_replace('254700000001', '25470****001', Fixtures::raw('c2b_confirmation'));
        self::assertSame('25470****001', CallbackParser::parseC2b($raw)->msisdn);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badC2b(): iterable
    {
        yield 'no TransID' => ['"TransID": "RKTQDM7W6S",', ''];
        yield 'bad time' => ['20191122063845', '2019-11-22'];
        yield 'zero amount' => ['"TransAmount": "10.00"', '"TransAmount": "0"'];
        yield 'garbage amount' => ['"TransAmount": "10.00"', '"TransAmount": "ten"'];
        yield 'no msisdn' => ['"MSISDN": "254700000001",', ''];
    }

    #[DataProvider('badC2b')]
    public function testRejectsBadC2b(string $find, string $replace): void
    {
        $raw = str_replace($find, $replace, Fixtures::raw('c2b_confirmation'));
        $this->expectException(InvalidCallbackPayloadException::class);
        CallbackParser::parseC2b($raw);
    }

    public function testParsesAB2cResult(): void
    {
        $r = CallbackParser::parseResult(Fixtures::raw('b2c_result'));
        self::assertTrue($r->isSuccessful());
        self::assertSame('AG_20191219_00004e48cf7e3533f581', $r->conversationId);
        self::assertSame('10571-7910404-1', $r->originatorConversationId);
        self::assertSame('NLJ41HAY6Q', $r->transactionId);
        self::assertSame(10, $r->parameter('TransactionAmount'));
        self::assertSame('NLJ41HAY6Q', $r->parameter('TransactionReceipt'));
        self::assertNull($r->parameter('Nope'));
    }

    public function testParsesAFailedResultWithoutParameters(): void
    {
        $raw = '{"Result":{"ResultType":0,"ResultCode":2001,"ResultDesc":"The initiator information is invalid.","OriginatorConversationID":"1-2-3","ConversationID":"AG_1","TransactionID":""}}';
        $r = CallbackParser::parseResult($raw);
        self::assertFalse($r->isSuccessful());
        self::assertSame(2001, $r->resultCode);
        self::assertSame([], $r->parameters);
    }

    public function testRejectsResultWithoutConversationId(): void
    {
        $this->expectException(InvalidCallbackPayloadException::class);
        CallbackParser::parseResult('{"Result":{"ResultCode":0,"ResultDesc":"x","OriginatorConversationID":"1"}}');
    }
}
