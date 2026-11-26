<?php

declare(strict_types=1);

namespace Daraja\Tests\Integration;

use Daraja\C2b\C2bValidationResponse;
use Daraja\Callback\CallbackParser;
use Daraja\Callback\StkCallback;
use Daraja\Config\DarajaConfig;
use Daraja\Daraja;
use Daraja\Exception\AuthenticationException;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\ExpectedPayment;
use Daraja\Safety\InMemoryIdempotencyStore;
use Daraja\Safety\ProcessOutcome;
use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkPushRequest;
use Daraja\Support\Json;
use Daraja\Tests\Support\BackgroundServers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End to end over real HTTP on localhost: the library's own cURL client talks to the real
 * `bin/daraja-mock` process, and the mock delivers callbacks to a real receiver process.
 * Offline: only loopback sockets are used.
 */
final class MockServerFlowTest extends TestCase
{
    private static BackgroundServers $servers;
    private Daraja $daraja;

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('curl') || !\extension_loaded('pdo_sqlite') || !\function_exists('proc_open')) {
            self::markTestSkipped('Needs ext-curl, ext-pdo_sqlite and proc_open.');
        }
        self::$servers = new BackgroundServers();
        self::$servers->start(['--retry-delays=0.2,0.2', '--stk-timeout=3']);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$servers)) {
            self::$servers->stop();
        }
    }

    protected function setUp(): void
    {
        self::$servers->resetReceiver();
        $this->consoleCall('POST', '/console/api/reset', []);
        $this->daraja = Daraja::create($this->config());
    }

    private function config(string $key = 'mock-consumer-key'): DarajaConfig
    {
        return new DarajaConfig($key, 'mock-consumer-secret', '174379', 'mock-passkey-not-a-real-secret', baseUrl: self::$servers->mockUrl(), timeoutSeconds: 5);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function consoleCall(string $method, string $path, ?array $body = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : Json::encode($body),
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $raw = file_get_contents(self::$servers->mockUrl() . $path, false, $ctx);
        self::assertIsString($raw);

        return Json::decodeObject($raw);
    }

    private function pushOrder(int $amount = 150, string $account = 'ORD-1'): string
    {
        return $this->daraja->stk()->push(new StkPushRequest($amount, '0700 000 001', self::$servers->receiverUrl('/hooks/stk'), $account, 'Order'))->checkoutRequestId;
    }

    private function pendingId(string $checkoutRequestId): int
    {
        $state = $this->consoleCall('GET', '/console/api/state');
        $pending = $state['pending'];
        self::assertIsArray($pending);
        foreach ($pending as $p) {
            if (\is_array($p) && ($p['checkoutRequestId'] ?? null) === $checkoutRequestId && \is_int($p['id'] ?? null)) {
                return $p['id'];
            }
        }
        self::fail('The STK prompt is not pending on the phone.');
    }

    private function respond(string $checkoutRequestId, string $action, bool $send = true): void
    {
        $r = $this->consoleCall('POST', '/console/api/stk/' . $this->pendingId($checkoutRequestId) . '/respond', ['action' => $action, 'sendCallback' => $send]);
        self::assertArrayHasKey('transaction', $r);
    }

    private function awaitCallbacks(int $count, float $seconds = 8): void
    {
        self::$servers->waitUntil(static fn (): bool => \count(array_filter(self::$servers->received(), static fn (array $r): bool => $r['status'] === 200)) >= $count, $seconds, "expected {$count} successful callback delivery");
    }

    public function testFullStkFlowOverRealHttp(): void
    {
        $checkoutId = $this->pushOrder(150, 'ORD-1');

        // The prompt reached the "phone", and the library sees it as still pending.
        self::assertGreaterThan(0, $this->pendingId($checkoutId));
        self::assertTrue($this->daraja->stk()->query($checkoutId)->pending);

        $this->respond($checkoutId, 'accept');
        $this->awaitCallbacks(1);

        $received = self::$servers->received();
        self::assertSame('/hooks/stk', $received[0]['path']);
        $callback = CallbackParser::parseStk($received[0]['body']);
        self::assertTrue($callback->isSuccessful());
        self::assertSame($checkoutId, $callback->checkoutRequestId);
        self::assertNotNull($callback->amount);
        self::assertSame(15000, $callback->amount->cents);
        self::assertNotNull($callback->phoneNumber);
        self::assertSame('254700000001', $callback->phoneNumber->value);
        self::assertNotNull($callback->mpesaReceiptNumber);

        // The safety layer accepts it against the order we created, and the query agrees.
        $credited = 0;
        $processor = new CallbackProcessor(new InMemoryIdempotencyStore());
        $result = $processor->processStk($received[0]['body'], static function () use (&$credited): void {
            ++$credited;
        }, ExpectedPayment::kes(150, '0700000001'));
        self::assertSame(ProcessOutcome::Handled, $result->outcome);
        self::assertSame(1, $credited);
        self::assertTrue($this->daraja->stk()->query($checkoutId)->isSuccessful());
    }

    public function testDuplicateDeliveriesCreditExactlyOnce(): void
    {
        $checkoutId = $this->pushOrder();
        $this->respond($checkoutId, 'accept');
        $this->awaitCallbacks(1);

        $state = $this->consoleCall('GET', '/console/api/state');
        $txns = $state['transactions'];
        self::assertIsArray($txns);
        $deliveries = \is_array($txns[0] ?? null) ? ($txns[0]['deliveries'] ?? null) : null;
        self::assertIsArray($deliveries);
        $deliveryId = \is_array($deliveries[0] ?? null) ? ($deliveries[0]['id'] ?? null) : null;
        self::assertIsInt($deliveryId);

        // Press "Redeliver" twice: the mock now sends the same callback three times in total.
        $this->consoleCall('POST', "/console/api/deliveries/{$deliveryId}/redeliver", []);
        $this->awaitCallbacks(2);
        $this->consoleCall('POST', "/console/api/deliveries/{$deliveryId}/redeliver", []);
        $this->awaitCallbacks(3);

        $processor = new CallbackProcessor(new InMemoryIdempotencyStore());
        $credits = 0;
        $outcomes = [];
        foreach (self::$servers->received() as $delivery) {
            $outcomes[] = $processor->processStk($delivery['body'], static function (StkCallback $cb) use (&$credits): void {
                ++$credits;
            })->outcome;
        }
        self::assertCount(3, $outcomes);
        self::assertSame(1, $credits);
        self::assertSame([ProcessOutcome::Handled, ProcessOutcome::Duplicate, ProcessOutcome::Duplicate], $outcomes);
    }

    public function testCallbacksAreRetriedUntilTheReceiverRecovers(): void
    {
        self::$servers->failNextReceiverRequests(2);
        $checkoutId = $this->pushOrder();
        $this->respond($checkoutId, 'accept');
        $this->awaitCallbacks(1);

        $statuses = array_map(static fn (array $r): int => $r['status'], self::$servers->received());
        self::assertSame([500, 500, 200], $statuses);
        // every attempt carried the identical body
        self::assertCount(1, array_unique(array_map(static fn (array $r): string => $r['body'], self::$servers->received())));
    }

    /** @return iterable<string, array{string, int, StkOutcome}> */
    public static function outcomes(): iterable
    {
        yield 'customer cancels' => ['cancel', 1032, StkOutcome::Cancelled];
        yield 'wrong PIN' => ['wrong_pin', 2001, StkOutcome::WrongPin];
        yield 'insufficient funds' => ['insufficient', 1, StkOutcome::InsufficientFunds];
        yield 'customer unreachable' => ['timeout', 1037, StkOutcome::TimedOut];
    }

    #[DataProvider('outcomes')]
    public function testEveryFailureOutcomeReachesTheCallbackAndTheQuery(string $action, int $code, StkOutcome $outcome): void
    {
        $checkoutId = $this->pushOrder();
        $this->respond($checkoutId, $action);
        $this->awaitCallbacks(1);

        $callback = CallbackParser::parseStk(self::$servers->received()[0]['body']);
        self::assertSame($code, $callback->resultCode);
        self::assertSame($outcome, $callback->outcome());

        $query = $this->daraja->stk()->query($checkoutId);
        self::assertSame($code, $query->resultCode);
        self::assertSame($outcome, $query->outcome());
    }

    public function testAnUnansweredPromptTimesOutByItself(): void
    {
        $checkoutId = $this->pushOrder();
        $this->awaitCallbacks(1, 12);
        $callback = CallbackParser::parseStk(self::$servers->received()[0]['body']);
        self::assertSame(1037, $callback->resultCode);
        self::assertSame($checkoutId, $callback->checkoutRequestId);
    }

    public function testALostCallbackCanBeReconciledWithAnStkQuery(): void
    {
        $checkoutId = $this->pushOrder();
        $this->respond($checkoutId, 'accept', false);

        usleep(600_000);
        self::assertSame([], self::$servers->received(), 'the mock must not have delivered anything');
        $query = $this->daraja->stk()->query($checkoutId);
        self::assertFalse($query->pending);
        self::assertTrue($query->isSuccessful());
    }

    public function testTheOauthTokenIsFetchedOnceAndReused(): void
    {
        $first = $this->pushOrder(10, 'A-1');
        $second = $this->pushOrder(20, 'A-2');
        self::assertNotSame($first, $second);
        $this->daraja->stk()->query($first);

        $state = $this->consoleCall('GET', '/console/api/state');
        $wire = $state['wire'];
        self::assertIsArray($wire);
        $oauthCalls = array_filter($wire, static fn (mixed $w): bool => \is_array($w) && ($w['path'] ?? null) === '/oauth/v1/generate');
        self::assertCount(1, $oauthCalls);
        self::assertCount(4, $wire);
    }

    public function testWrongCredentialsAreRejectedByTheServer(): void
    {
        $daraja = Daraja::create($this->config('not-the-key'));
        $this->expectException(AuthenticationException::class);
        $daraja->stk()->push(new StkPushRequest(10, '0700000001', self::$servers->receiverUrl('/x'), 'A', 'B'));
    }

    public function testC2bRegistrationSimulationAndConfirmation(): void
    {
        $this->daraja->c2b()->registerUrls(self::$servers->receiverUrl('/c2b/confirm'), self::$servers->receiverUrl('/c2b/validate'));
        $this->daraja->c2b()->simulate(500, '0700 000 002', 'INV-2026-1');
        $this->awaitCallbacks(2);

        $paths = array_map(static fn (array $r): string => $r['path'], self::$servers->received());
        self::assertSame(['/c2b/validate', '/c2b/confirm'], $paths);

        $tx = CallbackParser::parseC2b(self::$servers->received()[1]['body']);
        self::assertSame(50000, $tx->amount->cents);
        self::assertSame('INV-2026-1', $tx->billRefNumber);
        self::assertSame('{"ResultCode":"0","ResultDesc":"Accepted"}', C2bValidationResponse::accept()->toJson());
    }
}
