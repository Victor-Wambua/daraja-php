<?php

declare(strict_types=1);

/**
 * Produces demo/test/golden.json by running demo/test/cases.json through the REAL daraja-php code.
 * The JavaScript port in demo/js is then tested against that file, so the port cannot drift.
 *
 *   php demo/tools/golden.php           write demo/test/golden.json
 *   php demo/tools/golden.php --check   fail if the committed file differs from what the PHP code gives now
 *
 * Needs `composer install` (dev dependencies, for tests/Support). Offline, no network.
 */

use Daraja\Cache\ArrayCache;
use Daraja\Callback\CallbackParser;
use Daraja\Callback\StkCallback;
use Daraja\Daraja;
use Daraja\Exception\DarajaException;
use Daraja\Mock\MockConfig;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\ExpectedPayment;
use Daraja\Safety\InMemoryIdempotencyStore;
use Daraja\Stk\StkPassword;
use Daraja\Stk\StkPushRequest;
use Daraja\Stk\TransactionType;
use Daraja\Support\EatTime;
use Daraja\Support\FrozenClock;
use Daraja\Support\Json;
use Daraja\Support\Money;
use Daraja\Support\Msisdn;
use Daraja\Tests\Support\Fixtures;
use Daraja\Tests\Support\Harness;
use Daraja\Tests\Support\MockWorld;
use Daraja\Tests\Support\RecordingClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

const FROZEN = '2026-01-15 09:30:45 UTC';

/** @return array<mixed> */
function readJson(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    assert(is_array($data));

    return $data;
}

function shortName(\Throwable $e): string
{
    $parts = explode('\\', $e::class);

    return end($parts);
}

/** @return array{ok: false, type: string, message: string} */
function failure(\Throwable $e): array
{
    return ['ok' => false, 'type' => shortName($e), 'message' => $e->getMessage()];
}

/** Replace the random parts of ids so PHP and JS runs can be compared byte for byte otherwise. */
function normalise(string $s): string
{
    $s = preg_replace('/\b\d{5}-\d{8}-\d\b/', '<id>', $s) ?? $s;
    $s = preg_replace('/ws_CO_(\d{14})\d{9}/', 'ws_CO_$1<n>', $s) ?? $s;
    $s = preg_replace('/"MpesaReceiptNumber","Value":"[A-HJ-NP-Z0-9]{10}"/', '"MpesaReceiptNumber","Value":"<receipt>"', $s) ?? $s;

    return $s;
}

/** @return array{method: string, url: string, headers: list<array{string, string}>, body: string} */
function describeRequest(RequestInterface $r): array
{
    $headers = [];
    foreach ($r->getHeaders() as $name => $values) {
        $headers[] = [(string) $name, implode(', ', $values)];
    }

    return ['method' => $r->getMethod(), 'url' => (string) $r->getUri(), 'headers' => $headers, 'body' => normalise((string) $r->getBody())];
}

/** @return array{status: int, body: string} */
function describeResponse(ResponseInterface $r): array
{
    return ['status' => $r->getStatusCode(), 'body' => normalise((string) $r->getBody())];
}

/** @param array<string, mixed> $case */
function callbackRaw(array $case, string $root): string
{
    if (isset($case['oversized'])) {
        return '{"x":"' . str_repeat('a', CallbackParser::MAX_BODY_BYTES) . '"}';
    }
    if (isset($case['raw'])) {
        assert(is_string($case['raw']));

        return $case['raw'];
    }
    assert(is_string($case['fixture']));
    $raw = (string) file_get_contents($root . '/tests/Fixtures/' . $case['fixture'] . '.json');
    foreach ($case['replace'] ?? [] as $pair) {
        $raw = str_replace($pair[0], $pair[1], $raw);
    }

    return $raw;
}

/** @return array<string, mixed> */
function describeCallback(StkCallback $cb): array
{
    return [
        'merchantRequestId' => $cb->merchantRequestId,
        'checkoutRequestId' => $cb->checkoutRequestId,
        'resultCode' => $cb->resultCode,
        'resultDesc' => $cb->resultDesc,
        'amountCents' => $cb->amount?->cents,
        'receipt' => $cb->mpesaReceiptNumber,
        'transactionDate' => $cb->transactionDate?->format('c'),
        'phone' => $cb->phoneNumber?->value,
        'metadata' => (object) $cb->metadata,
        'isSuccessful' => $cb->isSuccessful(),
        'outcome' => $cb->outcome()->value,
        'resultName' => $cb->result()?->name,
    ];
}

/** @param array<string, mixed> $c */
function pushRequestFrom(array $c): StkPushRequest
{
    $type = ($c['type'] ?? 'PayBill') === 'BuyGoods' ? TransactionType::BuyGoods : TransactionType::PayBill;

    return new StkPushRequest(
        amount: (int) $c['amount'],
        phoneNumber: (string) $c['phone'],
        callbackUrl: (string) $c['callbackUrl'],
        accountReference: (string) $c['accountReference'],
        transactionDesc: (string) $c['transactionDesc'],
        type: $type,
        partyB: isset($c['partyB']) ? (string) $c['partyB'] : null,
    );
}

$cases = readJson(__DIR__ . '/../test/cases.json');
$golden = [
    '_about' => 'Written by demo/tools/golden.php from the real PHP code. Do not edit by hand.',
    'msisdn' => [],
    'money' => [],
    'timestamps' => [],
    'passwords' => [],
    'eatParse' => [],
    'urls' => [],
    'stkRequests' => [],
    'callbacks' => [],
    'processor' => [],
    'wire' => [],
    'mockBadFields' => [],
];

foreach ($cases['msisdn'] as $input) {
    $golden['msisdn'][] = ['input' => $input, 'result' => Msisdn::tryParse($input)?->value];
}

foreach ($cases['money'] as $input) {
    try {
        $m = Money::fromWire($input);
        $golden['money'][] = ['input' => $input, 'ok' => true, 'cents' => $m->cents, 'text' => (string) $m];
    } catch (\Throwable $e) {
        $golden['money'][] = ['input' => $input] + failure($e);
    }
}

foreach ($cases['timestamps'] as $iso) {
    $golden['timestamps'][] = ['input' => $iso, 'timestamp' => StkPassword::timestamp(new \DateTimeImmutable($iso))];
}

foreach ($cases['passwords'] as $p) {
    $golden['passwords'][] = $p + ['password' => StkPassword::generate($p['shortCode'], $p['passKey'], $p['timestamp'])];
}

foreach ($cases['eatParse'] as $input) {
    try {
        $golden['eatParse'][] = ['input' => $input, 'ok' => true, 'iso' => EatTime::parse($input)->format('c')];
    } catch (\Throwable $e) {
        $golden['eatParse'][] = ['input' => $input] + failure($e);
    }
}

foreach ($cases['urls'] as $url) {
    // The exact expression StkPushRequest and MockApp use.
    $golden['urls'][] = ['input' => $url, 'valid' => filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1];
}

foreach ($cases['stkRequests'] as $c) {
    $row = ['name' => $c['name']];
    try {
        $request = pushRequestFrom($c);
        $row['request'] = ['ok' => true, 'phone' => $request->phoneNumber->value, 'type' => $request->type->value];
        $h = Harness::make(RecordingClient::json(200, Fixtures::raw('stk_push_response')));
        $h->daraja->stk()->push($request);
        $row['push'] = ['ok' => true, 'requests' => array_map(describeRequest(...), $h->http->requests)];
    } catch (DarajaException $e) {
        if (!isset($row['request'])) {
            $row['request'] = failure($e);
        } else {
            $row['push'] = failure($e);
        }
    }
    $golden['stkRequests'][] = $row;
}

foreach ($cases['callbacks'] as $c) {
    $raw = callbackRaw($c, $root);
    $row = ['name' => $c['name']];
    try {
        $row += ['ok' => true, 'parsed' => describeCallback(CallbackParser::parseStk($raw))];
    } catch (\Throwable $e) {
        $row += failure($e);
    }
    $golden['callbacks'][] = $row;
}

foreach ($cases['processor'] as $seq) {
    $clock = new FrozenClock(FROZEN);
    $processor = new CallbackProcessor(new InMemoryIdempotencyStore($clock));
    $handlerCalls = 0;
    $steps = [];
    foreach ($seq['steps'] as $step) {
        $raw = callbackRaw($step, $root);
        $expected = $step['expected'] ?? null;
        if (is_array($expected)) {
            $expected = ExpectedPayment::kes((int) $expected['kes'], $expected['phone'] ?? null);
        } elseif ($expected === 'unknown') {
            $expected = static fn (StkCallback $cb): ?ExpectedPayment => null;
        }
        $inner = null;
        $handler = static function () use (&$handlerCalls, $step, $processor, $raw, &$inner): void {
            ++$handlerCalls;
            if (($step['handlerThrows'] ?? false) === true) {
                throw new \RuntimeException('database is down');
            }
            if (($step['reenter'] ?? false) === true) {
                $inner = $processor->processStk($raw, static function (): void {
                })->outcome->value;
            }
        };
        $row = [];
        try {
            $r = $processor->processStk($raw, $handler, $expected);
            $row = ['outcome' => $r->outcome->value, 'key' => $r->key, 'ack' => $r->acknowledgementJson()];
        } catch (\Throwable $e) {
            $row = ['exception' => ['type' => shortName($e), 'message' => $e->getMessage()]];
        }
        $row['handlerCalls'] = $handlerCalls;
        $row['inner'] = $inner;
        $steps[] = $row;
    }
    $golden['processor'][] = ['name' => $seq['name'], 'steps' => $steps];
}

/** A PSR-18 client that calls the mock in-process and remembers each exchange. */
$makeRecorder = static fn (ClientInterface $inner): object => new class($inner) implements ClientInterface {
    /** @var list<array{request: array<string, mixed>, response: array<string, mixed>}> */
    public array $log = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $this->log[] = ['request' => describeRequest($request), 'response' => describeResponse($response)];

        return $response;
    }
};

foreach ($cases['wire'] as $c) {
    foreach ($cases['wireActions'] as $action) {
        $w = new MockWorld(new MockConfig());
        $recorder = $makeRecorder($w->http());
        $daraja = Daraja::create(MockWorld::darajaConfig($w->config), $recorder, new ArrayCache($w->clock), null, $w->clock);

        $push = $daraja->stk()->push(pushRequestFrom($c));
        $first = $daraja->stk()->query($push->checkoutRequestId);
        $txn = $w->store->pendingStk()[0];
        $callbacks = [];

        if ($action === 'lost') {
            $w->app->scenarios()->resolveStk($txn->id, 'accept', false);
            $w->clock->advance(30);
        } elseif ($action === 'timeout') {
            $w->clock->advance($w->config->stkTimeoutSeconds);
            $w->outbound->enqueue(RecordingClient::json(200, '{"ResultCode":0}'));
            $w->dispatcher->tick();
        } else {
            $w->app->scenarios()->resolveStk($txn->id, $action);
            $w->outbound->enqueue(RecordingClient::json(200, '{"ResultCode":0}'));
            $w->dispatcher->tick();
        }
        foreach ($w->outbound->requests as $req) {
            $callbacks[] = describeRequest($req);
        }
        $after = $daraja->stk()->query($push->checkoutRequestId);

        $golden['wire'][] = [
            'name' => $c['name'],
            'action' => $action,
            'exchanges' => $recorder->log,
            'firstQueryPending' => $first->pending,
            'afterQuery' => ['pending' => $after->pending, 'resultCode' => $after->resultCode, 'outcome' => $after->outcome()?->value],
            'callbacks' => $callbacks,
        ];
    }
}

$validBody = static function (MockWorld $w): array {
    $timestamp = '20260115123045';

    return [
        'BusinessShortCode' => 174379,
        'Password' => StkPassword::generate('174379', $w->config->passKey, $timestamp),
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => 10,
        'PartyA' => 254700000001,
        'PartyB' => 174379,
        'PhoneNumber' => 254700000001,
        'CallBackURL' => 'http://127.0.0.1:4151/cb',
        'AccountReference' => 'A',
        'TransactionDesc' => 'B',
    ];
};

foreach ($cases['mockBadFields'] as [$field, $value]) {
    $w = new MockWorld(new MockConfig());
    $body = $validBody($w);
    $body[$field] = $value;
    $response = $w->call('POST', '/mpesa/stkpush/v1/processrequest', $body, $w->daraja->tokens->token());
    $golden['mockBadFields'][] = ['field' => $field, 'value' => $value, 'status' => $response->status, 'body' => normalise($response->body)];
}

// A request with no token, and with a forged one.
$w = new MockWorld(new MockConfig());
$golden['mockAuth'] = [
    'noToken' => ['status' => $w->call('POST', '/mpesa/stkpush/v1/processrequest', ['x' => 1])->status, 'body' => normalise($w->call('POST', '/mpesa/stkpush/v1/processrequest', ['x' => 1])->body)],
    'forged' => ['status' => $w->call('POST', '/mpesa/stkpush/v1/processrequest', ['x' => 1], 'forged-token')->status],
];

$json = json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
$target = __DIR__ . '/../test/golden.json';

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current !== $json) {
        fwrite(STDERR, "demo/test/golden.json is out of date with the PHP library. Run: php demo/tools/golden.php\n");
        exit(1);
    }
    echo "golden.json matches the PHP library (" . strlen($json) . " bytes).\n";
    exit(0);
}

file_put_contents($target, $json);
echo 'Wrote ' . $target . ' (' . strlen($json) . " bytes)\n";
