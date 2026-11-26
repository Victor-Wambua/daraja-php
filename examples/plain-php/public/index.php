<?php

declare(strict_types=1);

use Daraja\Exception\ApiException;
use Daraja\Exception\DarajaException;
use Daraja\Exception\InvalidCallbackPayloadException;
use Daraja\Exception\PaymentMismatchException;
use Daraja\Exception\UntrustedSourceException;
use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkPushRequest;
use Daraja\Support\Msisdn;
use Shop\Orders;

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Orders.php';

$app = bootstrap();
$orders = new Orders($app['pdo']);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

/** @return array<string, mixed> */
function publicOrder(array $o): array
{
    return [
        'ref' => $o['ref'], 'item' => $o['item'], 'amountKes' => $o['amount_kes'], 'status' => $o['status'],
        'detail' => $o['detail'], 'receipt' => $o['mpesa_receipt'], 'paidVia' => $o['paid_via'],
    ];
}

// ---- 1. The checkout page ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
    readfile(__DIR__ . '/checkout.html');
    exit;
}

// ---- 2. Start a payment: create the order, then send the STK prompt ---------------------------------
if ($method === 'POST' && $path === '/api/pay') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    $item = is_array($input) && is_string($input['item'] ?? null) ? $input['item'] : '';
    $phone = is_array($input) && is_string($input['phone'] ?? null) ? $input['phone'] : '';
    if (!isset(Orders::CATALOGUE[$item])) {
        respond(422, ['error' => 'Choose an item from the list.']);
    }

    $msisdn = Msisdn::tryParse($phone);
    if ($msisdn === null) {
        respond(422, ['error' => 'Enter a Safaricom number like 0700 000 001.']);
    }
    $order = $orders->create($item, $msisdn->value);

    try {
        $request = new StkPushRequest(
            amount: (int) $order['amount_kes'],
            phoneNumber: $msisdn->value,
            callbackUrl: $app['appUrl'] . '/hooks/stk/' . $app['secret'],
            accountReference: (string) $order['ref'],
            transactionDesc: 'Shop order',
        );
        $response = $app['daraja']->stk()->push($request);
        $orders->attachCheckoutId((string) $order['ref'], $response->checkoutRequestId);
    } catch (ApiException $e) {
        error_log('Daraja rejected the push: ' . $e->getMessage());
        $orders->markFailed((string) $order['ref'], 'The payment service refused the request');
        respond(502, ['error' => 'The payment service refused the request. Please try again.']);
    } catch (DarajaException $e) {
        error_log('Daraja error: ' . $e->getMessage());
        $orders->markFailed((string) $order['ref'], 'The payment service is unreachable');
        respond(502, ['error' => 'The payment service is unreachable. Please try again.']);
    }

    respond(202, ['order' => publicOrder($orders->byRef((string) $order['ref']) ?? $order)]);
}

// ---- 3. The page polls this to learn the outcome ----------------------------------------------------
if ($method === 'GET' && preg_match('#^/api/orders/(ORD-[A-F0-9]{6})$#', $path, $m) === 1) {
    $order = $orders->byRef($m[1]);
    $order === null ? respond(404, ['error' => 'No such order']) : respond(200, ['order' => publicOrder($order)]);
}

// ---- 4. "I never got the callback": ask Daraja directly (STK query) ----------------------------------
if ($method === 'POST' && preg_match('#^/api/orders/(ORD-[A-F0-9]{6})/reconcile$#', $path, $m) === 1) {
    $order = $orders->byRef($m[1]);
    if ($order === null || !is_string($order['checkout_request_id'])) {
        respond(404, ['error' => 'No such order']);
    }
    if ($order['status'] === 'pending') {
        try {
            $result = $app['daraja']->stk()->query($order['checkout_request_id']);
        } catch (DarajaException $e) {
            respond(502, ['error' => 'Could not reach the payment service.']);
        }
        if (!$result->pending) {
            $label = match ($result->outcome()) {
                StkOutcome::Success => 'paid',
                StkOutcome::Cancelled => 'cancelled',
                StkOutcome::TimedOut => 'timed_out',
                StkOutcome::InsufficientFunds => 'insufficient_funds',
                StkOutcome::WrongPin => 'wrong_pin',
                default => 'failed',
            };
            $orders->applyQuery($m[1], $result->isSuccessful(), $label, $result->resultDesc ?? '');
        }
    }
    respond(200, ['order' => publicOrder($orders->byRef($m[1]) ?? $order)]);
}

// ---- 5. Daraja calls this when the customer has answered the prompt ---------------------------------
if ($method === 'POST' && $path === '/hooks/stk/' . $app['secret']) {
    try {
        $result = $app['processor']->processStk(
            (string) file_get_contents('php://input'),
            static fn ($callback) => $orders->applyCallback($callback),
            static fn ($callback) => $orders->expectation($callback->checkoutRequestId),
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
        );
        header('Content-Type: application/json');
        echo $result->acknowledgementJson();
        exit;
    } catch (UntrustedSourceException) {
        respond(403, ['error' => 'forbidden']);
    } catch (InvalidCallbackPayloadException $e) {
        error_log('Bad callback: ' . $e->getMessage());
        respond(400, ['error' => 'bad payload']);
    } catch (PaymentMismatchException $e) {
        error_log('ALERT callback does not match its order: ' . $e->getMessage());
        respond(422, ['error' => 'mismatch']);
    } catch (Throwable $e) {
        error_log('Callback handler failed: ' . $e->getMessage());
        respond(500, ['error' => 'try again']);
    }
}

respond(404, ['error' => 'Not found']);
