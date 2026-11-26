<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Auth;

use Daraja\Auth\CachedTokenProvider;
use Daraja\Cache\ArrayCache;
use Daraja\Config\DarajaConfig;
use Daraja\Daraja;
use Daraja\Exception\ApiException;
use Daraja\Exception\AuthenticationException;
use Daraja\Exception\MalformedPayloadException;
use Daraja\Exception\TransportException;
use Daraja\Http\Transport;
use Daraja\Stk\StkPushRequest;
use Daraja\Support\FrozenClock;
use Daraja\Tests\Support\Fixtures;
use Daraja\Tests\Support\Harness;
use Daraja\Tests\Support\NetworkFailure;
use Daraja\Tests\Support\RecordingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class CachedTokenProviderTest extends TestCase
{
    private function provider(RecordingClient $http, FrozenClock $clock, ?ArrayCache $cache = null, ?\Closure $random = null, int $margin = 60): CachedTokenProvider
    {
        $config = new DarajaConfig('test-key', 'test-secret', '174379', 'p', tokenSafetyMarginSeconds: $margin, baseUrl: null);

        return new CachedTokenProvider($config, new Transport($http, new NullLogger()), $cache ?? new ArrayCache($clock), $clock, $random ?? static fn (): float => 1.0);
    }

    private function tokenResponse(string $token = 'tok', string $expires = '3599'): \Psr\Http\Message\ResponseInterface
    {
        return RecordingClient::json(200, json_encode(['access_token' => $token, 'expires_in' => $expires], JSON_THROW_ON_ERROR));
    }

    public function testFetchesOnceAndReuses(): void
    {
        $http = new RecordingClient($this->tokenResponse());
        $p = $this->provider($http, new FrozenClock());
        self::assertSame(['tok', 'tok', 'tok'], [$p->token(), $p->token(), $p->token()]);
        self::assertCount(1, $http->requests);
    }

    public function testRequestShape(): void
    {
        $http = new RecordingClient($this->tokenResponse());
        $this->provider($http, new FrozenClock())->token();
        $req = $http->last();
        self::assertSame('GET', $req->getMethod());
        self::assertSame('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials', (string) $req->getUri());
        self::assertSame('Basic ' . base64_encode('test-key:test-secret'), $req->getHeaderLine('Authorization'));
    }

    public function testSecondProcessReusesTheSharedCache(): void
    {
        $clock = new FrozenClock();
        $cache = new ArrayCache($clock);
        $first = new RecordingClient($this->tokenResponse('shared'));
        $this->provider($first, $clock, $cache)->token();

        $second = new RecordingClient();
        self::assertSame('shared', $this->provider($second, $clock, $cache)->token());
        self::assertCount(0, $second->requests);
    }

    public function testRefetchesAfterExpiryMinusSafetyMargin(): void
    {
        $clock = new FrozenClock();
        $http = new RecordingClient($this->tokenResponse('one', '3599'), $this->tokenResponse('two', '3599'));
        $p = $this->provider($http, $clock);
        $first = $p->token();
        $clock->advance(3599 - 61); // still 61 s before expiry, margin is 60
        self::assertSame(['one', 'one'], [$first, $p->token()]);
        self::assertCount(1, $http->requests);

        $clock->advance(2); // inside the margin now
        self::assertSame('two', $p->token());
        self::assertCount(2, $http->requests);
    }

    public function testVeryShortLivedTokensStillWork(): void
    {
        $clock = new FrozenClock();
        $http = new RecordingClient($this->tokenResponse('a', '30'), $this->tokenResponse('b', '30'));
        $p = $this->provider($http, $clock);
        self::assertSame('a', $p->token());
        $clock->advance(1);
        self::assertSame('b', $p->token());
    }

    public function testInvalidateForcesRefetch(): void
    {
        $http = new RecordingClient($this->tokenResponse('one'), $this->tokenResponse('two'));
        $p = $this->provider($http, new FrozenClock());
        self::assertSame('one', $p->token());
        $p->invalidate();
        self::assertSame('two', $p->token());
    }

    public function testProbabilisticEarlyRefreshSpreadsRefreshes(): void
    {
        $clock = new FrozenClock();
        $cache = new ArrayCache($clock);
        // Seed a cache entry that expires in 5 s and took a (pretend) 2 s to fetch.
        $now = $clock->now()->getTimestamp();
        $cache->set('daraja_token_' . hash('sha256', 'https://sandbox.safaricom.co.ke|test-key'), ['value' => 'old', 'expiresAt' => $now + 5, 'fetchSeconds' => 2.0], 5);

        // A dice roll near 1 means "no early refresh yet": -log(r) is about 0.
        $keeps = $this->provider(new RecordingClient(), $clock, $cache, static fn (): float => 0.99);
        self::assertSame('old', $keeps->token());

        // A tiny roll means a large refresh pressure: now + 2 * -log(0.001) = now + 13.8 >= now + 5.
        $http = new RecordingClient($this->tokenResponse('fresh'));
        $refreshes = $this->provider($http, $clock, $cache, static fn (): float => 0.001);
        self::assertSame('fresh', $refreshes->token());
        self::assertCount(1, $http->requests);
    }

    public function testBadCredentialsRaiseAuthenticationException(): void
    {
        $http = new RecordingClient(RecordingClient::json(400, '{"requestId":"","errorCode":"400.008.01","errorMessage":"Invalid Authentication passed"}'));
        $p = $this->provider($http, new FrozenClock());
        try {
            $p->token();
            self::fail('expected exception');
        } catch (AuthenticationException $e) {
            self::assertSame('400.008.01', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testMalformedTokenResponse(): void
    {
        $p = $this->provider(new RecordingClient(RecordingClient::json(200, '{"unexpected":true}')), new FrozenClock());
        $this->expectException(MalformedPayloadException::class);
        $p->token();
    }

    public function testNonJsonTokenResponse(): void
    {
        $p = $this->provider(new RecordingClient(RecordingClient::json(200, '<html>gateway</html>')), new FrozenClock());
        $this->expectException(MalformedPayloadException::class);
        $p->token();
    }

    public function testNetworkFailureBecomesTransportException(): void
    {
        $p = $this->provider(new RecordingClient(new NetworkFailure('connection refused')), new FrozenClock());
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection refused');
        $p->token();
    }

    public function testRejectedTokenIsRefreshedAndTheCallRetriedExactlyOnce(): void
    {
        $h = Harness::make(
            RecordingClient::json(401, Fixtures::raw('error_invalid_token')),
            RecordingClient::json(200, '{"access_token":"fresh-token","expires_in":"3599"}'),
            RecordingClient::json(200, Fixtures::raw('stk_push_response')),
        );
        $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Order'));

        $uris = array_map(static fn ($r): string => $r->getUri()->getPath(), $h->http->requests);
        self::assertSame(['/oauth/v1/generate', '/mpesa/stkpush/v1/processrequest', '/oauth/v1/generate', '/mpesa/stkpush/v1/processrequest'], $uris);
        self::assertSame('Bearer fresh-token', $h->http->last()->getHeaderLine('Authorization'));
    }

    public function testPersistent401IsNotRetriedForever(): void
    {
        $h = Harness::make(
            RecordingClient::json(401, Fixtures::raw('error_invalid_token')),
            RecordingClient::json(200, '{"access_token":"again","expires_in":"3599"}'),
            RecordingClient::json(401, Fixtures::raw('error_invalid_token')),
        );
        $this->expectException(AuthenticationException::class);
        $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Order'));
    }

    public function testServerErrorsAreNotRetried(): void
    {
        $h = Harness::make(RecordingClient::json(503, '{"errorCode":"500.003.02","errorMessage":"System is busy"}'));
        try {
            $h->daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Order'));
            self::fail('expected exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isServerSide());
            self::assertSame('500.003.02', $e->errorCode);
        }
        // one token call and one push, no automatic replay of a payment request
        self::assertCount(2, $h->http->requests);
    }

    public function testSecretsNeverReachTheLogs(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (\is_string($level) ? $level : '') . ' ' . $message . ' ' . json_encode($context);
            }
        };
        $http = new RecordingClient(
            RecordingClient::json(200, '{"access_token":"visible-token-value","expires_in":"3599"}'),
            RecordingClient::json(200, Fixtures::raw('stk_push_response')),
        );
        $clock = new FrozenClock();
        $daraja = Daraja::create(Harness::config(), $http, new ArrayCache($clock), $logger, $clock);
        $daraja->stk()->push(new StkPushRequest(150, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Order'));

        $log = implode("\n", $logger->lines);
        self::assertNotSame('', $log);
        foreach (['visible-token-value', 'test-secret', 'test-key', 'test-passkey', '254700000001', base64_encode('test-key:test-secret')] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }
}
