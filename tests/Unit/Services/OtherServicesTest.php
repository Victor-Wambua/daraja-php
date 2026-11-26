<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Services;

use Daraja\B2c\B2cCommand;
use Daraja\B2c\B2cRequest;
use Daraja\C2b\C2bRejectCode;
use Daraja\C2b\C2bResponseType;
use Daraja\C2b\C2bValidationResponse;
use Daraja\Config\DarajaConfig;
use Daraja\Config\Environment;
use Daraja\Exception\ApiException;
use Daraja\Exception\ConfigurationException;
use Daraja\Exception\ValidationException;
use Daraja\Status\IdentifierType;
use Daraja\Tests\Support\Fixtures;
use Daraja\Tests\Support\Harness;
use Daraja\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class OtherServicesTest extends TestCase
{
    public function testC2bRegisterUrls(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('c2b_register_response')));
        $r = $h->daraja->c2b()->registerUrls('https://shop.example/c2b/confirm', 'https://shop.example/c2b/validate', C2bResponseType::Cancelled);
        self::assertSame('7619-37765134-1', $r['originatorConversationId']);
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl', (string) $h->http->last()->getUri());
        self::assertSame([
            'ShortCode' => 174379, 'ResponseType' => 'Cancelled',
            'ConfirmationURL' => 'https://shop.example/c2b/confirm', 'ValidationURL' => 'https://shop.example/c2b/validate',
        ], $h->http->lastJsonBody());
    }

    public function testC2bRegisterUrlsVersionOne(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('c2b_register_response')));
        $h->daraja->c2b()->registerUrls('https://a.example/c', 'https://a.example/v', version: 1);
        self::assertStringEndsWith('/mpesa/c2b/v1/registerurl', (string) $h->http->last()->getUri());
    }

    public function testC2bRegisterUrlsValidation(): void
    {
        $h = Harness::with(Harness::config(Environment::Production), true);
        $this->expectException(ValidationException::class);
        $h->daraja->c2b()->registerUrls('http://a.example/c', 'https://a.example/v');
    }

    public function testC2bSimulateSendsDocumentedFields(): void
    {
        $h = Harness::make(RecordingClient::json(200, '{"OriginatorCoversationID":"1-2-3","ConversationID":"AG_1","ResponseDescription":"Accept the service request successfully."}'));
        $r = $h->daraja->c2b()->simulate(500, '0700 000 002', 'INV-1');
        self::assertSame('AG_1', $r['conversationId']);
        self::assertSame([
            'ShortCode' => 174379, 'CommandID' => 'CustomerPayBillOnline', 'Amount' => 500, 'Msisdn' => 254700000002, 'BillRefNumber' => 'INV-1',
        ], $h->http->lastJsonBody());
    }

    public function testC2bSimulateExistsOnlyInSandbox(): void
    {
        $h = Harness::with(Harness::config(Environment::Production), true);
        $this->expectException(ConfigurationException::class);
        $h->daraja->c2b()->simulate(500, '0700000002', 'INV-1');
    }

    public function testValidationResponses(): void
    {
        self::assertSame('{"ResultCode":"0","ResultDesc":"Accepted"}', C2bValidationResponse::accept()->toJson());
        self::assertSame(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Unknown account'], C2bValidationResponse::reject(C2bRejectCode::InvalidAccountNumber, 'Unknown account')->toArray());
    }

    public function testB2cPayment(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('async_ack')));
        $request = new B2cRequest(250, '0700000001', 'Refund', 'https://shop.example/b2c/result', 'https://shop.example/b2c/timeout', B2cCommand::BusinessPayment, 'Refund-1', 'orig-conv-0001');
        $ack = $h->daraja->b2c()->pay($request);
        self::assertSame('AG_20191219_00005797af5d7d75f652', $ack->conversationId);

        $body = $h->http->lastJsonBody();
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/b2c/v1/paymentrequest', (string) $h->http->last()->getUri());
        self::assertSame('orig-conv-0001', $body['OriginatorConversationID']);
        self::assertSame('testapi', $body['InitiatorName']);
        self::assertSame('ENCRYPTED-CREDENTIAL', $body['SecurityCredential']);
        self::assertSame('BusinessPayment', $body['CommandID']);
        self::assertSame(250, $body['Amount']);
        self::assertSame(174379, $body['PartyA']);
        self::assertSame(254700000001, $body['PartyB']);
        self::assertSame('https://shop.example/b2c/result', $body['ResultURL']);
        self::assertSame('https://shop.example/b2c/timeout', $body['QueueTimeOutURL']);
    }

    public function testB2cGeneratesAnOriginatorIdWhenOmitted(): void
    {
        $a = new B2cRequest(1, '0700000001', 'x', 'https://a.example/r', 'https://a.example/t');
        $b = new B2cRequest(1, '0700000001', 'x', 'https://a.example/r', 'https://a.example/t');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a->originatorConversationId);
        self::assertNotSame($a->originatorConversationId, $b->originatorConversationId);
    }

    public function testB2cValidation(): void
    {
        $this->expectException(ValidationException::class);
        new B2cRequest(0, '0700000001', 'x', 'https://a.example/r', 'https://a.example/t');
    }

    public function testB2cNeedsInitiatorSettings(): void
    {
        $h = Harness::with(new DarajaConfig('k', 's', '174379', 'p'), true);
        $this->expectException(ConfigurationException::class);
        $h->daraja->b2c()->pay(new B2cRequest(10, '0700000001', 'x', 'https://a.example/r', 'https://a.example/t'));
    }

    public function testB2cProductionRequiresHttpsResultUrls(): void
    {
        $h = Harness::with(Harness::config(Environment::Production), true);
        $this->expectException(ValidationException::class);
        $h->daraja->b2c()->pay(new B2cRequest(10, '0700000001', 'x', 'http://a.example/r', 'https://a.example/t'));
    }

    public function testB2cRejectionIsAnApiException(): void
    {
        $h = Harness::make(RecordingClient::json(400, '{"requestId":"r","errorCode":"500.002.1001","errorMessage":"Initiator information is invalid"}'));
        try {
            $h->daraja->b2c()->pay(new B2cRequest(10, '0700000001', 'x', 'https://a.example/r', 'https://a.example/t'));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('500.002.1001', $e->errorCode);
        }
    }

    public function testTransactionStatusQuery(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('async_ack')));
        $h->daraja->transactionStatus()->query('NLJ7RT61SV', 'https://a.example/r', 'https://a.example/t', IdentifierType::ShortCode);
        $body = $h->http->lastJsonBody();
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query', (string) $h->http->last()->getUri());
        self::assertSame('TransactionStatusQuery', $body['CommandID']);
        self::assertSame('NLJ7RT61SV', $body['TransactionID']);
        self::assertSame('4', $body['IdentifierType']);
        self::assertSame(174379, $body['PartyA']);
    }

    public function testTransactionStatusRejectsOddReceipts(): void
    {
        $h = Harness::make();
        $this->expectException(ValidationException::class);
        $h->daraja->transactionStatus()->query('x y', 'https://a.example/r', 'https://a.example/t');
    }

    public function testReversal(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('async_ack')));
        $h->daraja->reversal()->reverse('NLJ7RT61SV', 150, 'https://a.example/r', 'https://a.example/t', 'Duplicate charge');
        $body = $h->http->lastJsonBody();
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/reversal/v1/request', (string) $h->http->last()->getUri());
        self::assertSame('TransactionReversal', $body['CommandID']);
        self::assertSame(150, $body['Amount']);
        self::assertSame('11', $body['RecieverIdentifierType']);
        self::assertSame(174379, $body['ReceiverParty']);
    }

    public function testReversalValidation(): void
    {
        $h = Harness::make();
        $this->expectException(ValidationException::class);
        $h->daraja->reversal()->reverse('NLJ7RT61SV', 0, 'https://a.example/r', 'https://a.example/t');
    }
}
