<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Stk;

use Daraja\Config\DarajaConfig;
use Daraja\Config\Environment;
use Daraja\Exception\ApiException;
use Daraja\Exception\ConfigurationException;
use Daraja\Exception\ValidationException;
use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkPushRequest;
use Daraja\Stk\TransactionType;
use Daraja\Tests\Support\Fixtures;
use Daraja\Tests\Support\Harness;
use Daraja\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class StkServiceTest extends TestCase
{
    private function request(): StkPushRequest
    {
        return new StkPushRequest(150, '0700 000 001', 'https://shop.example/hooks/stk', 'ORD-1', 'Shop order');
    }

    public function testPushSendsTheDocumentedBody(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_push_response')));
        $response = $h->daraja->stk()->push($this->request());

        self::assertSame('ws_CO_191220191020363925', $response->checkoutRequestId);
        self::assertSame('29115-34620561-1', $response->merchantRequestId);

        $req = $h->http->last();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest', (string) $req->getUri());
        self::assertSame('Bearer fake-access-token-0001', $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        $body = $h->http->lastJsonBody();
        self::assertSame(
            ['BusinessShortCode', 'Password', 'Timestamp', 'TransactionType', 'Amount', 'PartyA', 'PartyB', 'PhoneNumber', 'CallBackURL', 'AccountReference', 'TransactionDesc'],
            array_keys($body),
        );
        self::assertSame(174379, $body['BusinessShortCode']);
        self::assertSame('CustomerPayBillOnline', $body['TransactionType']);
        self::assertSame(150, $body['Amount']);
        self::assertSame(254700000001, $body['PartyA']);
        self::assertSame(174379, $body['PartyB']);
        self::assertSame(254700000001, $body['PhoneNumber']);
        self::assertSame('https://shop.example/hooks/stk', $body['CallBackURL']);
        self::assertSame('ORD-1', $body['AccountReference']);
    }

    public function testPasswordAndTimestampAreConsistent(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_push_response')));
        $h->daraja->stk()->push($this->request());
        $body = $h->http->lastJsonBody();

        // The harness clock is 09:30:45 UTC, which is 12:30:45 East Africa Time.
        self::assertSame('20260115123045', $body['Timestamp']);
        self::assertIsString($body['Password']);
        self::assertSame('174379test-passkey20260115123045', base64_decode($body['Password'], true));
    }

    public function testBuyGoodsUsesTillAsPartyB(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_push_response')));
        $request = new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Shop order', TransactionType::BuyGoods, '5123456');
        $h->daraja->stk()->push($request);
        $body = $h->http->lastJsonBody();
        self::assertSame('CustomerBuyGoodsOnline', $body['TransactionType']);
        self::assertSame(5123456, $body['PartyB']);
        self::assertSame(174379, $body['BusinessShortCode']);
    }

    public function testApiErrorKeepsDarajaCodes(): void
    {
        $h = Harness::make(RecordingClient::json(400, Fixtures::raw('error_invalid_phone')));
        try {
            $h->daraja->stk()->push($this->request());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('400.002.02', $e->errorCode);
            self::assertSame('Bad Request - Invalid PhoneNumber', $e->errorMessage);
            self::assertSame('11728-2929992-1', $e->requestId);
            self::assertStringContainsString('400.002.02', $e->getMessage());
            self::assertFalse($e->isServerSide());
        }
    }

    public function testNonZeroResponseCodeOnHttp200IsAnError(): void
    {
        $body = Fixtures::with('stk_push_response', static fn (array $d): array => ['ResponseCode' => '1', 'ResponseDescription' => 'Rejected'] + $d);
        $h = Harness::make(RecordingClient::json(200, $body));
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Rejected');
        $h->daraja->stk()->push($this->request());
    }

    public function testRequiresAPasskey(): void
    {
        $config = new DarajaConfig('k', 's', '174379');
        $h = Harness::with($config, true);
        $this->expectException(ConfigurationException::class);
        $h->daraja->stk()->push($this->request());
    }

    public function testEnforcesConfiguredFieldLengths(): void
    {
        $h = Harness::make();
        $this->expectException(ValidationException::class);
        $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'THIS-REFERENCE-IS-TOO-LONG', 'Order'));
    }

    public function testFieldLengthLimitsAreConfigurable(): void
    {
        $config = new DarajaConfig('k', 's', '174379', 'p', accountReferenceMaxLength: 30, transactionDescMaxLength: 30);
        $h = Harness::with($config, true, RecordingClient::json(200, Fixtures::raw('stk_push_response')));
        $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'A-LONGER-REFERENCE-ALLOWED', 'A longer description'));
        self::assertCount(2, $h->http->requests);
    }

    public function testProductionRequiresHttpsCallback(): void
    {
        $h = Harness::with(Harness::config(Environment::Production), true);
        $this->expectException(ValidationException::class);
        $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'http://shop.example/cb', 'ORD-1', 'Order'));
    }

    public function testQuerySuccess(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_query_success')));
        $result = $h->daraja->stk()->query('ws_CO_191220191020363925');
        self::assertFalse($result->pending);
        self::assertTrue($result->isSuccessful());
        self::assertSame(StkOutcome::Success, $result->outcome());
        $body = $h->http->lastJsonBody();
        self::assertSame('ws_CO_191220191020363925', $body['CheckoutRequestID']);
        self::assertSame('https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query', (string) $h->http->last()->getUri());
    }

    public function testQueryCancelled(): void
    {
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_query_cancelled')));
        $result = $h->daraja->stk()->query('ws_CO_191220191020363925');
        self::assertSame(1032, $result->resultCode);
        self::assertSame(StkOutcome::Cancelled, $result->outcome());
        self::assertFalse($result->isSuccessful());
    }

    public function testQueryWhilePendingIsNotAnException(): void
    {
        $h = Harness::make(RecordingClient::json(500, Fixtures::raw('stk_query_pending_error')));
        $result = $h->daraja->stk()->query('ws_CO_191220191020363925');
        self::assertTrue($result->pending);
        self::assertNull($result->outcome());
        self::assertFalse($result->isSuccessful());
    }

    public function testOtherQueryErrorsStillThrow(): void
    {
        $h = Harness::make(RecordingClient::json(400, Fixtures::raw('error_invalid_phone')));
        $this->expectException(ApiException::class);
        $h->daraja->stk()->query('ws_CO_191220191020363925');
    }

    public function testQueryRejectsMalformedId(): void
    {
        $h = Harness::make();
        $this->expectException(ValidationException::class);
        $h->daraja->stk()->query("abc'; DROP TABLE");
    }
}
