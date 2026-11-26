<?php

declare(strict_types=1);

namespace App\Providers;

use Daraja\Config\DarajaConfig;
use Daraja\Daraja;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\IdempotencyStore;
use Daraja\Safety\IpAllowList;
use Daraja\Safety\PdoIdempotencyStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class DarajaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Daraja::class, static function (): Daraja {
            $config = DarajaConfig::fromEnv([
                'DARAJA_ENV' => config('daraja.environment'),
                'DARAJA_CONSUMER_KEY' => config('daraja.consumer_key'),
                'DARAJA_CONSUMER_SECRET' => config('daraja.consumer_secret'),
                'DARAJA_SHORTCODE' => config('daraja.shortcode'),
                'DARAJA_PASSKEY' => config('daraja.passkey'),
                'DARAJA_BASE_URL' => config('daraja.base_url'),
            ]);

            // Laravel's cache repository and logger both implement the PSR interfaces the library asks for.
            // Use a shared store (redis, database, file) so every worker reuses the same OAuth token.
            return Daraja::create($config, cache: Cache::store(), logger: Log::channel());
        });

        $this->app->singleton(IdempotencyStore::class, static fn (): IdempotencyStore => new PdoIdempotencyStore(DB::connection()->getPdo()));

        $this->app->singleton(CallbackProcessor::class, function (): CallbackProcessor {
            $ips = config('daraja.callback_allowed_ips');

            return new CallbackProcessor(
                $this->app->make(IdempotencyStore::class),
                \is_array($ips) && $ips !== [] ? new IpAllowList($ips) : null,
                Log::channel(),
            );
        });
    }
}
