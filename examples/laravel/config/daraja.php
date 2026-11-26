<?php

declare(strict_types=1);

return [
    'environment' => env('DARAJA_ENV', 'sandbox'),
    'consumer_key' => env('DARAJA_CONSUMER_KEY'),
    'consumer_secret' => env('DARAJA_CONSUMER_SECRET'),
    'shortcode' => env('DARAJA_SHORTCODE'),
    'passkey' => env('DARAJA_PASSKEY'),
    // Leave empty for Safaricom. Set to http://127.0.0.1:4150 to use the bundled mock server.
    'base_url' => env('DARAJA_BASE_URL'),

    // A long random string. It becomes part of the callback URL so strangers cannot guess it.
    'callback_secret' => env('DARAJA_CALLBACK_SECRET'),

    // Comma separated addresses/CIDRs you trust for callbacks, copied from Safaricom's documentation.
    // Empty means "do not filter by IP". This package ships no Safaricom addresses.
    'callback_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('DARAJA_CALLBACK_ALLOWED_IPS', ''))))),
];
