<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Mock;

use Daraja\B2c\B2cRequest;
use Daraja\Callback\CallbackParser;
use Daraja\Mock\TokenIssuer;
use Daraja\Status\IdentifierType;
use Daraja\Tests\Support\MockWorld;
use Daraja\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class MockApisTest extends TestCase
{
    public function testTokenIssuer(): void
    {
        $issuer = new TokenIssuer('key');
        $token = $issuer->issue(1000);
        self::assertTrue($issuer->isValid($token, 999));
        self::assertFalse($issuer->isValid($token, 1000));
        self::assertFalse((new TokenIssuer('other'))->isValid($token, 0));
        self::assertFalse($issuer->isValid('garbage', 0));
        self::assertFalse($issuer->isValid('', 0));
    }

    public function testC2bSimulationDeliversAConfirmationThatParses(): void
    {
        $w = new MockWorld();
        $w->daraja->c2b()->registerUrls('http://127.0.0.1:4151/c2b/confirm', 'http://127.0.0.1:4151/c2b/validate');
        $w->outbound->enqueue(RecordingClient::json(200, '{"ResultCode":"0","ResultDesc":"Accepted"}'));
        $w->daraja->c2b()->simulate(500, '0700000002', 'INV-1');

        // the validation URL was asked first
        self::assertSame('http://127.0.0.1:4151/c2b/validate', (string) $w->outbound->last()->getUri());

        $delivery = $w->store->latestDeliveries(1)[0];
        self::assertSame('C2B confirmation', $delivery->label);
        self::assertSame('http://127.0.0.1:4151/c2b/confirm', $delivery->url);
        $tx = CallbackParser::parseC2b($delivery->payload);
        self::assertSame(50000, $tx->amount->cents);
        self::assertSame('INV-1', $tx->billRefNumber);
        self::assertSame('254700000002', $tx->msisdn);
    }

    public function testARejectingValidationUrlStopsTheConfirmation(): void
    {
        $w = new MockWorld();
        $w->daraja->c2b()->registerUrls('http://127.0.0.1:4151/c', 'http://127.0.0.1:4151/v');
        $w->outbound->enqueue(RecordingClient::json(200, '{"ResultCode":"C2B00012","ResultDesc":"Unknown account"}'));
        $w->daraja->c2b()->simulate(500, '0700000002', 'NOPE');

        self::assertSame([], $w->store->latestDeliveries(5));
        self::assertSame('rejected', $w->store->latestTransactions(1)[0]->status);
    }

    public function testUnreachableValidationFollowsTheRegisteredResponseType(): void
    {
        $w = new MockWorld();
        $w->daraja->c2b()->registerUrls('http://127.0.0.1:4151/c', 'http://127.0.0.1:4151/v', \Daraja\C2b\C2bResponseType::Cancelled);
        $w->outbound->enqueue(new \Daraja\Tests\Support\NetworkFailure('down'));
        $w->daraja->c2b()->simulate(500, '0700000002', 'INV-1');
        self::assertSame('rejected', $w->store->latestTransactions(1)[0]->status);

        $w2 = new MockWorld();
        $w2->daraja->c2b()->registerUrls('http://127.0.0.1:4151/c', 'http://127.0.0.1:4151/v', \Daraja\C2b\C2bResponseType::Completed);
        $w2->outbound->enqueue(new \Daraja\Tests\Support\NetworkFailure('down'));
        $w2->daraja->c2b()->simulate(500, '0700000002', 'INV-1');
        self::assertSame('success', $w2->store->latestTransactions(1)[0]->status);
    }

    public function testB2cProducesAParsableResult(): void
    {
        $w = new MockWorld();
        $ack = $w->daraja->b2c()->pay(new B2cRequest(250, '0700000001', 'Refund', 'http://127.0.0.1:4151/r', 'http://127.0.0.1:4151/t', originatorConversationId: 'orig-0001-xyz'));
        self::assertSame('0', $ack->responseCode);
        self::assertSame('orig-0001-xyz', $ack->originatorConversationId);

        $delivery = $w->store->latestDeliveries(1)[0];
        $result = CallbackParser::parseResult($delivery->payload);
        self::assertTrue($result->isSuccessful());
        self::assertSame($ack->conversationId, $result->conversationId);
        self::assertSame('orig-0001-xyz', $result->originatorConversationId);
        self::assertSame(250.0, $result->parameter('TransactionAmount'));
    }

    public function testTransactionStatusAnswersForAKnownReceipt(): void
    {
        $w = new MockWorld();
        $w->daraja->stk()->push(new \Daraja\Stk\StkPushRequest(150, '0700000001', 'http://127.0.0.1:4151/cb', 'ORD-1', 'Order'));
        $txn = $w->app->scenarios()->resolveStk($w->store->pendingStk()[0]->id, 'accept', false);
        self::assertNotNull($txn);

        $w->daraja->transactionStatus()->query($txn->receipt, 'http://127.0.0.1:4151/status', 'http://127.0.0.1:4151/t', IdentifierType::ShortCode);
        $result = CallbackParser::parseResult($w->store->latestDeliveries(1)[0]->payload);
        self::assertTrue($result->isSuccessful());
        self::assertSame($txn->receipt, $result->parameter('ReceiptNo'));
        self::assertSame('Completed', $result->parameter('TransactionStatus'));
        self::assertSame(150.0, $result->parameter('Amount'));
    }

    public function testTransactionStatusForAnUnknownReceiptReportsFailure(): void
    {
        $w = new MockWorld();
        $w->daraja->transactionStatus()->query('ZZZZZZZZZZ', 'http://127.0.0.1:4151/status', 'http://127.0.0.1:4151/t');
        $result = CallbackParser::parseResult($w->store->latestDeliveries(1)[0]->payload);
        self::assertFalse($result->isSuccessful());
    }

    public function testReversalMarksThePaymentReversed(): void
    {
        $w = new MockWorld();
        $w->daraja->stk()->push(new \Daraja\Stk\StkPushRequest(150, '0700000001', 'http://127.0.0.1:4151/cb', 'ORD-1', 'Order'));
        $txn = $w->app->scenarios()->resolveStk($w->store->pendingStk()[0]->id, 'accept', false);
        self::assertNotNull($txn);

        $w->daraja->reversal()->reverse($txn->receipt, 150, 'http://127.0.0.1:4151/r', 'http://127.0.0.1:4151/t');
        $stored = $w->store->transaction($txn->id);
        self::assertNotNull($stored);
        self::assertSame('reversed', $stored->status);
        self::assertTrue(CallbackParser::parseResult($w->store->latestDeliveries(1)[0]->payload)->isSuccessful());

        // reversing it a second time fails
        $w->daraja->reversal()->reverse($txn->receipt, 150, 'http://127.0.0.1:4151/r', 'http://127.0.0.1:4151/t');
        self::assertFalse(CallbackParser::parseResult($w->store->latestDeliveries(1)[0]->payload)->isSuccessful());
    }

    public function testWireLogRecordsCallsButHidesTheToken(): void
    {
        $w = new MockWorld();
        $w->daraja->stk()->push(new \Daraja\Stk\StkPushRequest(150, '0700000001', 'http://127.0.0.1:4151/cb', 'ORD-1', 'Order'));
        $wire = $w->store->latestWire(10);
        self::assertCount(2, $wire);
        $paths = array_map(static fn (array $r): mixed => $r['path'], $wire);
        self::assertSame(['/mpesa/stkpush/v1/processrequest', '/oauth/v1/generate'], $paths);
        $oauth = $wire[1]['response_body'];
        self::assertIsString($oauth);
        self::assertStringContainsString('[hidden]', $oauth);
    }

    public function testUnknownRoutesAndMethods(): void
    {
        $w = new MockWorld();
        self::assertSame(404, $w->call('GET', '/nope')->status);
        self::assertSame(405, $w->call('GET', '/mpesa/stkpush/v1/processrequest')->status);
        self::assertSame(404, $w->call('POST', '/mpesa/unknown/v1/thing', [], $w->daraja->tokens->token())->status);
    }
}
