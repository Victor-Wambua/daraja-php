<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in web server (`php -S host:port router.php`).
 * Every request is a fresh PHP process, so all state lives in the SQLite file named by the config.
 */

use Daraja\Http\CurlClient;
use Daraja\Mock\MockApp;
use Daraja\Mock\MockConfig;
use Daraja\Mock\MockRequest;
use Daraja\Mock\Store;
use Daraja\Support\Json;
use Daraja\Support\SystemClock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configJson = getenv('DARAJA_MOCK_CONFIG');
$dbPath = getenv('DARAJA_MOCK_DB');
if (!is_string($configJson) || !is_string($dbPath)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "daraja-mock: start this server with bin/daraja-mock, not with php -S directly.\n";

    return true;
}

try {
    $config = MockConfig::fromArray(Json::decodeObject($configJson));
} catch (\JsonException) {
    http_response_code(500);
    echo 'Bad DARAJA_MOCK_CONFIG';

    return true;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (is_string($value) && str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
$query = parse_url($uri, PHP_URL_QUERY);
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$body = file_get_contents('php://input');

$app = new MockApp(Store::open($dbPath), $config, new SystemClock(), new CurlClient($config->callbackTimeoutSeconds, 3));
$response = $app->handle(new MockRequest(
    $method,
    is_string($path) ? $path : '/',
    is_string($query) ? $query : '',
    $headers,
    $body === false ? '' : $body,
));

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;

return true;
