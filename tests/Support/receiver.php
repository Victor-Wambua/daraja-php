<?php

declare(strict_types=1);

/**
 * A tiny callback receiver for the integration tests (run with `php -S ... receiver.php`).
 * It appends every POST to the file named by RECEIVER_FILE as one JSON line. If RECEIVER_FILE.fail
 * contains a number N, the next N requests are answered with HTTP 500 (still logged).
 */

$file = getenv('RECEIVER_FILE');
if (!is_string($file)) {
    http_response_code(500);

    return true;
}

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

$fail = 0;
$failFile = $file . '.fail';
if (is_file($failFile)) {
    $fail = (int) file_get_contents($failFile);
    if ($fail > 0) {
        file_put_contents($failFile, (string) ($fail - 1));
    }
}

file_put_contents($file, json_encode(['path' => $path, 'status' => $fail > 0 ? 500 : 200, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if ($fail > 0) {
    http_response_code(500);
    echo '{"error":"temporarily broken"}';

    return true;
}
echo '{"ResultCode":0,"ResultDesc":"Accepted"}';

return true;
