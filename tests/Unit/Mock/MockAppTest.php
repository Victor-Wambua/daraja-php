<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Mock;

use Daraja\Exception\ApiException;
use Daraja\Exception\AuthenticationException;
use Daraja\Mock\MockConfig;
use Daraja\Mock\MockRequest;
use Daraja\Mock\Scenarios;
use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkPassword;
use Daraja\Stk\StkPushRequest;
use Daraja\Tests\Support\MockWorld;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MockAppTest extends TestCase
{
    private function push(MockWorld $w, int $amount = 150, string $callback = 'http://127.0.0.1:4151/cb'): string
    {
        return $w->daraja->stk()->push(new StkPushRequest($amount, '0700 000 001', $callback, 'ORD-1', 'Order'))->checkoutRequestId;
    }

    public function testHealth(): void
    {
        self::assertSame(200, (new MockWorld())->call('GET', '/__health')->status);
    }

    public function testOauthRejectsBadCredentials(): void
    {
        $w = new MockWorld();
        $bad = new \Daraja\Config\DarajaConfig('wrong', 'wrong', '174379', 'p', baseUrl: 'http://127.0.0.1:4150');
        $daraja = \Daraja\Daraja::create($bad, $w->http(), null, null, $w->clock);
        $this->expectException(AuthenticationException::class);
        $daraja->stk()->push(new StkPushRequest(10, '0700000001', 'http://127.0.0.1:1/cb', 'A', 'B'));
    }

    public function testOauthChecksGrantType(): void
    {
        $w = new MockWorld();
        $r = $w->app->handle(new MockRequest('GET', '/oauth/v1/generate', 'grant_type=password', ['authorization' => 'Basic ' . base64_encode('mock-consumer-key:mock-consumer-secret')], ''));
        self::assertSame(400, $r->status);
    }

    public function testApiRequiresABearerToken(): void
    {
        $w = new MockWorld();
        $r = $w->call('POST', '/mpesa/stkpush/v1/processrequest', ['x' => 1]);
        self::assertSame(401, $r->status);
        self::assertSame('404.001.03', MockWorld::decode($r)['errorCode']);
        self::assertSame(401, $w->call('POST', '/mpesa/stkpush/v1/processrequest', ['x' => 1], 'forged-token')->status);
    }

    public function testTokensExpire(): void
    {
        $w = new MockWorld(new MockConfig(tokenTtlSeconds: 100));
        $token = $w->daraja->tokens->token();
        self::assertNotSame(401, $w->call('POST', '/mpesa/stkpushquery/v1/query', [], $token)->status);
        $w->clock->advance(101);
        self::assertSame(401, $w->call('POST', '/mpesa/stkpushquery/v1/query', [], $token)->status);
    }

    public function testStkPushCreatesAPendingPrompt(): void
    {
        $w = new MockWorld();
        $id = $this->push($w, 150);
        self::assertStringStartsWith('ws_CO_', $id);
        $pending = $w->store->pendingStk();
        self::assertCount(1, $pending);
        self::assertSame(15000, $pending[0]->amountCents);
        self::assertSame('254700000001', $pending[0]->msisdn);
        self::assertSame($id, $pending[0]->checkoutRequestId);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function badPushFields(): iterable
    {
        yield 'amount zero' => ['Amount', 0, 'Amount'];
        yield 'amount text' => ['Amount', 'lots', 'Amount'];
        yield 'phone' => ['PhoneNumber', 712345, 'PhoneNumber'];
        yield 'type' => ['TransactionType', 'Teleport', 'TransactionType'];
        yield 'shortcode' => ['BusinessShortCode', 999999, 'BusinessShortCode'];
        yield 'password' => ['Password', 'bm9wZQ==', 'Password'];
        yield 'timestamp' => ['Timestamp', 'now', 'Timestamp'];
        yield 'callback' => ['CallBackURL', 'javascript:alert(1)', 'CallBackURL'];
    }

    #[DataProvider('badPushFields')]
    public function testStkPushValidatesLikeDaraja(string $field, mixed $value, string $named): void
    {
        $w = new MockWorld();
        $timestamp = '20260115123045';
        $body = [
            'BusinessShortCode' => 174379,
            'Password' => StkPassword::generate('174379', $w->config->passKey, $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => 10,
            'PartyA' => 254700000001,
            'PartyB' => 174379,
            'PhoneNumber' => 254700000001,
            'CallBackURL' => 'http://127.0.0.1:4151/cb',
            'AccountReference' => 'A',
            'TransactionDesc' => 'B',
        ];
        $body[$field] = $value;
        $r = $w->call('POST', '/mpesa/stkpush/v1/processrequest', $body, $w->daraja->tokens->token());
        self::assertSame(400, $r->status);
        $decoded = MockWorld::decode($r);
        self::assertSame('400.002.02', $decoded['errorCode']);
        self::assertSame('Bad Request - Invalid ' . $named, $decoded['errorMessage']);
        self::assertSame([], $w->store->pendingStk());
    }

    public function testQueryReportsPendingThenTheResult(): void
    {
        $w = new MockWorld();
        $id = $this->push($w);
        self::assertTrue($w->daraja->stk()->query($id)->pending);

        $txn = $w->store->pendingStk()[0];
        $w->app->scenarios()->resolveStk($txn->id, 'cancel');
        $result = $w->daraja->stk()->query($id);
        self::assertFalse($result->pending);
        self::assertSame(1032, $result->resultCode);
        self::assertSame(StkOutcome::Cancelled, $result->outcome());
    }

    public function testQueryForAnUnknownIdIsABadRequest(): void
    {
        $w = new MockWorld();
        $this->expectException(ApiException::class);
        $w->daraja->stk()->query('ws_CO_000000000000000000');
    }

    /** @return iterable<string, array{string, int, StkOutcome, bool}> */
    public static function actions(): iterable
    {
        yield 'accept' => ['accept', 0, StkOutcome::Success, true];
        yield 'cancel' => ['cancel', 1032, StkOutcome::Cancelled, false];
        yield 'timeout' => ['timeout', 1037, StkOutcome::TimedOut, false];
        yield 'insufficient' => ['insufficient', 1, StkOutcome::InsufficientFunds, false];
        yield 'wrong pin' => ['wrong_pin', 2001, StkOutcome::WrongPin, false];
    }

    #[DataProvider('actions')]
    public function testEveryConsoleActionProducesTheRightCallback(string $action, int $code, StkOutcome $outcome, bool $paid): void
    {
        $w = new MockWorld();
        $this->push($w);
        $txn = $w->store->pendingStk()[0];

        $resolved = $w->app->scenarios()->resolveStk($txn->id, $action);
        self::assertNotNull($resolved);
        self::assertSame($code, $resolved->resultCode);
        self::assertSame($paid, $resolved->receipt !== '');

        $deliveries = $w->store->latestDeliveries(10);
        self::assertCount(1, $deliveries);
        $cb = \Daraja\Callback\CallbackParser::parseStk($deliveries[0]->payload);
        self::assertSame($code, $cb->resultCode);
        self::assertSame($outcome, $cb->outcome());
        self::assertSame($txn->checkoutRequestId, $cb->checkoutRequestId);
        if ($paid) {
            self::assertSame($resolved->receipt, $cb->mpesaReceiptNumber);
            self::assertNotNull($cb->amount);
            self::assertSame(15000, $cb->amount->cents);
        }
    }

    public function testAPromptCanOnlyBeAnsweredOnce(): void
    {
        $w = new MockWorld();
        $this->push($w);
        $id = $w->store->pendingStk()[0]->id;
        self::assertNotNull($w->app->scenarios()->resolveStk($id, 'accept'));
        self::assertNull($w->app->scenarios()->resolveStk($id, 'cancel'));
        self::assertCount(1, $w->store->latestDeliveries(10));
    }

    public function testLosingTheCallbackQueuesNothingUntilAskedTo(): void
    {
        $w = new MockWorld();
        $this->push($w);
        $txn = $w->store->pendingStk()[0];
        $resolved = $w->app->scenarios()->resolveStk($txn->id, 'accept', false);
        self::assertNotNull($resolved);
        self::assertTrue($resolved->callbackWithheld);
        self::assertSame([], $w->store->latestDeliveries(10));

        $r = $w->call('POST', '/console/api/transactions/' . $txn->id . '/send-callback', []);
        self::assertSame(200, $r->status);
        self::assertCount(1, $w->store->latestDeliveries(10));
    }

    public function testConsoleStateListsPendingPrompts(): void
    {
        $w = new MockWorld();
        $this->push($w);
        $state = MockWorld::decode($w->call('GET', '/console/api/state'));
        self::assertIsArray($state['pending']);
        self::assertCount(1, $state['pending']);
        self::assertIsArray($state['config']);
        self::assertSame('174379', $state['config']['shortCode']);
    }

    public function testConsoleRespondEndpoint(): void
    {
        $w = new MockWorld();
        $this->push($w);
        $id = $w->store->pendingStk()[0]->id;
        self::assertSame(400, $w->call('POST', "/console/api/stk/{$id}/respond", ['action' => 'explode'])->status);
        self::assertSame(200, $w->call('POST', "/console/api/stk/{$id}/respond", ['action' => 'accept'])->status);
        self::assertSame(409, $w->call('POST', "/console/api/stk/{$id}/respond", ['action' => 'accept'])->status);
    }

    public function testConsoleRefusesForeignHostsAndFormPosts(): void
    {
        $w = new MockWorld();
        self::assertSame(403, $w->call('GET', '/console/api/state', null, '', 'evil.example')->status, 'DNS-rebinding style Host header');
        self::assertSame(415, $w->call('POST', '/console/api/reset', ['a' => 1], '', '127.0.0.1:4150', 'application/x-www-form-urlencoded')->status);

        $cross = $w->app->handle(new MockRequest('POST', '/console/api/reset', '', ['host' => '127.0.0.1:4150', 'origin' => 'https://evil.example', 'content-type' => 'application/json'], '{}'));
        self::assertSame(403, $cross->status);
    }

    public function testConsoleServesHtmlWithSecurityHeaders(): void
    {
        $r = (new MockWorld())->call('GET', '/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('daraja-mock', $r->body);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertStringContainsString("default-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('https://', (string) preg_replace('#http://www\.w3\.org/2000/svg#', '', $r->body), 'console must not load anything from the network');
    }

    public function testAmountsAreStoredAsIntegerCents(): void
    {
        $w = new MockWorld();
        $this->push($w, 1);
        self::assertSame(100, $w->store->pendingStk()[0]->amountCents);
    }

    public function testStkPromptsTimeOutOnTheDispatcherTick(): void
    {
        $w = new MockWorld(new MockConfig(stkTimeoutSeconds: 30, retryDelays: []));
        $id = $this->push($w);
        $w->outbound->enqueue(\Daraja\Tests\Support\RecordingClient::json(200, '{}'));
        $w->clock->advance(29);
        $w->dispatcher->tick();
        self::assertTrue($w->daraja->stk()->query($id)->pending);

        $w->clock->advance(2);
        $w->dispatcher->tick();
        $q = $w->daraja->stk()->query($id);
        self::assertSame(1037, $q->resultCode);
        self::assertSame(StkOutcome::TimedOut, $q->outcome());
        self::assertSame(['accept', 'cancel', 'timeout', 'insufficient', 'wrong_pin'], Scenarios::actions());
    }
}
