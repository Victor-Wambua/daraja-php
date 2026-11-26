<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Mock;

use Daraja\Mock\MockConfig;
use Daraja\Stk\StkPushRequest;
use Daraja\Tests\Support\MockWorld;
use Daraja\Tests\Support\NetworkFailure;
use Daraja\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    private function paidWorld(MockConfig $config): MockWorld
    {
        $w = new MockWorld($config);
        $w->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'http://127.0.0.1:4151/hooks/stk', 'ORD-1', 'Order'));
        $w->app->scenarios()->resolveStk($w->store->pendingStk()[0]->id, 'accept');

        return $w;
    }

    public function testDeliversTheCallbackAsJsonToTheRegisteredUrl(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: [1.0]));
        $w->outbound->enqueue(RecordingClient::json(200, '{"ResultCode":0}'));
        self::assertSame(1, $w->dispatcher->tick());

        $req = $w->outbound->last();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('http://127.0.0.1:4151/hooks/stk', (string) $req->getUri());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $req->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('Body', $decoded);

        $d = $w->store->latestDeliveries(1)[0];
        self::assertSame('delivered', $d->status);
        self::assertSame(200, $d->lastHttpStatus);
        self::assertSame(1, $d->attempts);
    }

    public function testRetriesFailuresOnTheConfiguredSchedule(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: [5.0, 10.0]));
        $w->outbound->enqueue(RecordingClient::json(500, 'oops'), new NetworkFailure('refused'), RecordingClient::json(200, '{}'));

        self::assertSame(1, $w->dispatcher->tick()); // attempt 1 fails with HTTP 500
        self::assertSame(0, $w->dispatcher->tick(), 'not due yet');
        $d = $w->store->latestDeliveries(1)[0];
        self::assertSame('pending', $d->status);
        self::assertSame('HTTP 500', $d->lastError);

        $w->clock->advance(5);
        self::assertSame(1, $w->dispatcher->tick()); // attempt 2 fails with a network error
        self::assertSame('refused', $w->store->latestDeliveries(1)[0]->lastError);
        $w->clock->advance(9);
        self::assertSame(0, $w->dispatcher->tick());
        $w->clock->advance(1);
        self::assertSame(1, $w->dispatcher->tick()); // attempt 3 succeeds

        $d = $w->store->latestDeliveries(1)[0];
        self::assertSame('delivered', $d->status);
        self::assertSame(3, $d->attempts);
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: [1.0]));
        $w->outbound->enqueue(RecordingClient::json(500, ''), RecordingClient::json(500, ''));
        $w->dispatcher->tick();
        $w->clock->advance(1);
        $w->dispatcher->tick();
        $w->clock->advance(100);
        self::assertSame(0, $w->dispatcher->tick());
        $d = $w->store->latestDeliveries(1)[0];
        self::assertSame('failed', $d->status);
        self::assertSame(2, $d->attempts);
    }

    public function testNoRetriesWhenTheScheduleIsEmpty(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: []));
        $w->outbound->enqueue(RecordingClient::json(500, ''));
        $w->dispatcher->tick();
        self::assertSame('failed', $w->store->latestDeliveries(1)[0]->status);
    }

    public function testRedeliverSendsTheSamePayloadAgain(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: [1.0]));
        $w->outbound->enqueue(RecordingClient::json(200, '{}'), RecordingClient::json(200, '{}'));
        $w->dispatcher->tick();
        $id = $w->store->latestDeliveries(1)[0]->id;

        $r = $w->call('POST', "/console/api/deliveries/{$id}/redeliver", []);
        self::assertSame(200, $r->status);
        $w->dispatcher->tick();

        self::assertCount(2, $w->outbound->requests);
        self::assertSame((string) $w->outbound->requests[0]->getBody(), (string) $w->outbound->requests[1]->getBody());
    }

    public function testASecondWorkerCannotClaimAnInFlightDelivery(): void
    {
        $w = $this->paidWorld(new MockConfig(retryDelays: [1.0]));
        $d = $w->store->latestDeliveries(1)[0];
        $now = $w->app->scenarios()->now();
        self::assertTrue($w->store->claimDelivery($d->id, $now, 10));
        self::assertFalse($w->store->claimDelivery($d->id, $now, 10));
    }
}
