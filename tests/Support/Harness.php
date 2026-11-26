<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

use Daraja\Cache\ArrayCache;
use Daraja\Config\DarajaConfig;
use Daraja\Config\Environment;
use Daraja\Daraja;
use Daraja\Support\FrozenClock;
use Psr\Http\Message\ResponseInterface;

/** A Daraja client wired to a scripted HTTP client, a frozen clock and an array cache. */
final readonly class Harness
{
    public function __construct(
        public Daraja $daraja,
        public RecordingClient $http,
        public FrozenClock $clock,
        public ArrayCache $cache,
        public DarajaConfig $config,
    ) {
    }

    /**
     * @param ResponseInterface ...$responses replies after the automatic token response
     */
    public static function make(ResponseInterface|\Throwable ...$responses): self
    {
        return self::with(self::config(), true, ...$responses);
    }

    public static function config(Environment $env = Environment::Sandbox, ?string $baseUrl = null): DarajaConfig
    {
        return new DarajaConfig(
            consumerKey: 'test-key',
            consumerSecret: 'test-secret',
            shortCode: '174379',
            passKey: 'test-passkey',
            environment: $env,
            baseUrl: $baseUrl,
            initiatorName: 'testapi',
            securityCredential: 'ENCRYPTED-CREDENTIAL',
        );
    }

    public static function with(DarajaConfig $config, bool $withToken, ResponseInterface|\Throwable ...$responses): self
    {
        $queue = $withToken ? [RecordingClient::json(200, Fixtures::raw('oauth_token')), ...$responses] : $responses;
        $http = new RecordingClient(...$queue);
        $clock = new FrozenClock('2026-01-15 09:30:45 UTC');
        $cache = new ArrayCache($clock);

        return new self(Daraja::create($config, $http, $cache, null, $clock), $http, $clock, $cache, $config);
    }
}
