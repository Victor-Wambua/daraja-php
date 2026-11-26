<?php

declare(strict_types=1);

namespace Daraja;

use Daraja\Auth\CachedTokenProvider;
use Daraja\Auth\TokenProvider;
use Daraja\B2c\B2cService;
use Daraja\C2b\C2bService;
use Daraja\Cache\ArrayCache;
use Daraja\Config\DarajaConfig;
use Daraja\Http\ApiClient;
use Daraja\Http\CurlClient;
use Daraja\Http\Transport;
use Daraja\Reversal\ReversalService;
use Daraja\Status\TransactionStatusService;
use Daraja\Stk\StkService;
use Daraja\Support\Clock;
use Daraja\Support\SystemClock;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Entry point. One instance per Daraja app (one set of credentials).
 *
 *     $daraja = Daraja::create(DarajaConfig::fromEnv($_ENV), cache: $psr16Cache);
 *     $response = $daraja->stk()->push(new StkPushRequest(...));
 */
final readonly class Daraja
{
    private function __construct(
        private DarajaConfig $config,
        private ApiClient $api,
        private Clock $clock,
        public TokenProvider $tokens,
    ) {
    }

    /**
     * @param ClientInterface|null $http   Any PSR-18 client. Defaults to the bundled cURL client.
     * @param CacheInterface|null  $cache  Where the OAuth token is kept. Defaults to a per-process array cache,
     *                                     so pass a shared cache (APCu, Redis, files) to reuse tokens across requests.
     */
    public static function create(
        DarajaConfig $config,
        ?ClientInterface $http = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
        ?Clock $clock = null,
    ): self {
        $clock ??= new SystemClock();
        $transport = new Transport($http ?? new CurlClient($config->timeoutSeconds), $logger ?? new NullLogger());
        $tokens = new CachedTokenProvider($config, $transport, $cache ?? new ArrayCache($clock), $clock);

        return new self($config, new ApiClient($config, $transport, $tokens), $clock, $tokens);
    }

    public function stk(): StkService
    {
        return new StkService($this->config, $this->api, $this->clock);
    }

    public function c2b(): C2bService
    {
        return new C2bService($this->config, $this->api);
    }

    public function b2c(): B2cService
    {
        return new B2cService($this->config, $this->api);
    }

    public function transactionStatus(): TransactionStatusService
    {
        return new TransactionStatusService($this->config, $this->api);
    }

    public function reversal(): ReversalService
    {
        return new ReversalService($this->config, $this->api);
    }
}
