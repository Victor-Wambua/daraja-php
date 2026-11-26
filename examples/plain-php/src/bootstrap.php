<?php

declare(strict_types=1);

/**
 * Wiring for the plain-PHP example: one PDO connection, one Daraja client, one callback processor.
 * Defaults point at the bundled mock server so the demo runs with no credentials.
 */

use Daraja\Config\DarajaConfig;
use Daraja\Daraja;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\IpAllowList;
use Daraja\Safety\PdoIdempotencyStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @return array{pdo: PDO, daraja: Daraja, processor: CallbackProcessor, appUrl: string, secret: string} */
function bootstrap(): array
{
    $env = static function (string $key, string $default): string {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    };

    $varDir = __DIR__ . '/../var';
    $pdo = new PDO('sqlite:' . $varDir . '/shop.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref TEXT NOT NULL UNIQUE,
            item TEXT NOT NULL,
            amount_kes INTEGER NOT NULL,
            phone TEXT NOT NULL,
            status TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT \'\',
            checkout_request_id TEXT NULL,
            mpesa_receipt TEXT NULL UNIQUE,
            paid_via TEXT NULL,
            created_at TEXT NOT NULL
        )',
    );

    // The callback URL contains an unguessable path segment, so a stranger who finds the endpoint cannot post to it.
    $secretFile = $varDir . '/callback-secret.txt';
    if (!is_file($secretFile)) {
        file_put_contents($secretFile, bin2hex(random_bytes(16)));
    }
    $secret = trim((string) file_get_contents($secretFile));

    $config = new DarajaConfig(
        consumerKey: $env('DARAJA_CONSUMER_KEY', 'mock-consumer-key'),
        consumerSecret: $env('DARAJA_CONSUMER_SECRET', 'mock-consumer-secret'),
        shortCode: $env('DARAJA_SHORTCODE', '174379'),
        passKey: $env('DARAJA_PASSKEY', 'mock-passkey-not-a-real-secret'),
        baseUrl: $env('DARAJA_BASE_URL', 'http://127.0.0.1:4150'),
    );

    $store = new PdoIdempotencyStore($pdo);
    $store->migrate();

    // Optional: only accept callbacks from addresses you configure (comma separated, taken from Safaricom's docs).
    $allowed = array_values(array_filter(array_map('trim', explode(',', $env('DARAJA_CALLBACK_ALLOWED_IPS', '')))));
    $processor = new CallbackProcessor($store, $allowed === [] ? null : new IpAllowList($allowed));

    return [
        'pdo' => $pdo,
        'daraja' => Daraja::create($config),
        'processor' => $processor,
        'appUrl' => rtrim($env('APP_URL', 'http://127.0.0.1:4151'), '/'),
        'secret' => $secret,
    ];
}
