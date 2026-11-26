<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

use Daraja\Cache\ArrayCache;
use Daraja\Config\DarajaConfig;
use Daraja\Daraja;
use Daraja\Mock\Dispatcher;
use Daraja\Mock\MockApp;
use Daraja\Mock\MockConfig;
use Daraja\Mock\MockRequest;
use Daraja\Mock\MockResponse;
use Daraja\Mock\Store;
use Daraja\Support\FrozenClock;
use Psr\Http\Client\ClientInterface;

/** An in-memory mock server, a Daraja client pointed at it, and a controllable clock. */
final class MockWorld
{
    public readonly Store $store;
    public readonly FrozenClock $clock;
    public readonly MockApp $app;
    public readonly Daraja $daraja;
    public readonly Dispatcher $dispatcher;
    public readonly RecordingClient $outbound;

    public function __construct(public readonly MockConfig $config = new MockConfig(retryDelays: [1.0, 2.0]))
    {
        $this->store = Store::open(':memory:');
        $this->clock = new FrozenClock('2026-01-15 09:30:45 UTC');
        $this->outbound = new RecordingClient();
        $this->app = new MockApp($this->store, $this->config, $this->clock, $this->outbound);
        $this->dispatcher = new Dispatcher($this->store, $this->outbound, $this->config, $this->app->scenarios());
        $this->daraja = Daraja::create(self::darajaConfig($this->config), new MockAppClient($this->app), new ArrayCache($this->clock), null, $this->clock);
    }

    public static function darajaConfig(MockConfig $c): DarajaConfig
    {
        return new DarajaConfig(
            consumerKey: $c->consumerKey,
            consumerSecret: $c->consumerSecret,
            shortCode: $c->shortCode,
            passKey: $c->passKey,
            baseUrl: 'http://127.0.0.1:4150',
            initiatorName: 'testapi',
            securityCredential: 'cred',
        );
    }

    public function http(): ClientInterface
    {
        return new MockAppClient($this->app);
    }

    /** @param array<string, mixed>|null $json */
    public function call(string $method, string $path, ?array $json = null, string $token = '', string $host = '127.0.0.1:4150', string $contentType = 'application/json'): MockResponse
    {
        $headers = ['host' => $host];
        if ($json !== null) {
            $headers['content-type'] = $contentType;
        }
        if ($token !== '') {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return $this->app->handle(new MockRequest($method, $path, '', $headers, $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR)));
    }

    /** @return array<mixed> */
    public static function decode(MockResponse $r): array
    {
        $data = json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        return $data;
    }
}
