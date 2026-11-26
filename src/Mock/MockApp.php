<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Callback\C2bTransaction;
use Daraja\Http\Psr7\Request;
use Daraja\Http\Psr7\Stream;
use Daraja\Stk\StkPassword;
use Daraja\Support\Clock;
use Daraja\Support\Json;
use Daraja\Support\Msisdn;
use Daraja\Support\Payload;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * The mock Daraja server as a plain function: request in, response out. The PHP built-in server
 * router, the tests and the in-process PSR-18 bridge all call handle().
 *
 * Error bodies copy Daraja's {requestId, errorCode, errorMessage} shape. The exact codes and messages
 * are approximations made from public documentation and developer reports, not recorded traffic.
 */
final class MockApp
{
    private readonly Scenarios $scenarios;
    private readonly TokenIssuer $tokens;

    public function __construct(
        private readonly Store $store,
        private readonly MockConfig $config,
        private readonly Clock $clock,
        private readonly ClientInterface $http,
    ) {
        $this->scenarios = new Scenarios($store, $clock, $config);
        $this->tokens = new TokenIssuer($config->signingKey);
    }

    public function scenarios(): Scenarios
    {
        return $this->scenarios;
    }

    public function handle(MockRequest $request): MockResponse
    {
        $started = hrtime(true);
        $response = $this->route($request);
        $response = new MockResponse($response->status, $response->body, $response->headers + [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ]);

        if (str_starts_with($request->path, '/oauth/') || str_starts_with($request->path, '/mpesa/')) {
            $responseBody = str_starts_with($request->path, '/oauth/') ? '{"access_token":"[hidden]","expires_in":"' . $this->config->tokenTtlSeconds . '"}' : $response->body;
            $this->store->logWire($this->scenarios->now(), $request->method, $request->path, $response->status, (int) ((hrtime(true) - $started) / 1_000_000), $request->body, $responseBody);
        }

        return $response;
    }

    private function route(MockRequest $r): MockResponse
    {
        $path = $r->path;
        $method = $r->method;

        if ($path === '/__health') {
            return MockResponse::json(200, ['status' => 'ok', 'mock' => 'daraja-php']);
        }
        if ($path === '/' || $path === '/index.html') {
            return $method === 'GET' ? $this->console() : $this->methodNotAllowed();
        }
        if (str_starts_with($path, '/console/api/')) {
            return $this->consoleApi($r);
        }
        if ($path === '/oauth/v1/generate') {
            return $method === 'GET' ? $this->oauth($r) : $this->methodNotAllowed();
        }
        if (str_starts_with($path, '/mpesa/')) {
            return $method === 'POST' ? $this->mpesa($r) : $this->methodNotAllowed();
        }

        return $this->error(404, '404.001.01', 'Resource not found');
    }

    // ---------------------------------------------------------------- OAuth

    private function oauth(MockRequest $r): MockResponse
    {
        parse_str($r->query, $query);
        if (($query['grant_type'] ?? null) !== 'client_credentials') {
            return $this->error(400, '400.008.02', 'Invalid grant type passed');
        }
        $header = $r->header('authorization');
        if (!str_starts_with($header, 'Basic ')) {
            return $this->error(400, '400.008.01', 'Invalid Authentication passed');
        }
        $decoded = base64_decode(substr($header, 6), true);
        $expected = $this->config->consumerKey . ':' . $this->config->consumerSecret;
        if ($decoded === false || !hash_equals($expected, $decoded)) {
            return $this->error(400, '400.008.01', 'Invalid Authentication passed');
        }
        $ttl = $this->config->tokenTtlSeconds;

        return MockResponse::json(200, [
            'access_token' => $this->tokens->issue($this->clock->now()->getTimestamp() + $ttl),
            'expires_in' => (string) $ttl,
        ]);
    }

    // ---------------------------------------------------------------- API

    private function mpesa(MockRequest $r): MockResponse
    {
        $header = $r->header('authorization');
        if (!str_starts_with($header, 'Bearer ') || !$this->tokens->isValid(substr($header, 7), $this->clock->now()->getTimestamp())) {
            return $this->error(401, '404.001.03', 'Invalid Access Token');
        }
        try {
            $body = Payload::root(Json::decodeObject($r->body));
        } catch (\JsonException) {
            return $this->error(400, '400.002.05', 'Invalid Request Payload');
        }

        try {
            return match (true) {
                $r->path === '/mpesa/stkpush/v1/processrequest' => $this->stkPush($body),
                $r->path === '/mpesa/stkpushquery/v1/query' => $this->stkQuery($body),
                preg_match('#^/mpesa/c2b/v[12]/registerurl$#', $r->path) === 1 => $this->c2bRegister($body),
                $r->path === '/mpesa/c2b/v1/simulate' => $this->c2bSimulate($body),
                preg_match('#^/mpesa/b2c/v[123]/paymentrequest$#', $r->path) === 1 => $this->b2c($body),
                $r->path === '/mpesa/transactionstatus/v1/query' => $this->transactionStatus($body),
                $r->path === '/mpesa/reversal/v1/request' => $this->reversal($body),
                default => $this->error(404, '404.001.01', 'Resource not found'),
            };
        } catch (BadField $e) {
            return $this->error(400, '400.002.02', 'Bad Request - Invalid ' . $e->field);
        } catch (\Daraja\Exception\MalformedPayloadException $e) {
            return $this->error(400, '400.002.05', 'Invalid Request Payload');
        }
    }

    private function stkPush(Payload $b): MockResponse
    {
        $this->checkShortCodeAndPassword($b);
        $type = $b->string('TransactionType');
        if (!\in_array($type, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
            throw new BadField('TransactionType');
        }
        $amount = $b->scalar('Amount');
        if ((!\is_int($amount) && !(\is_string($amount) && preg_match('/^\d{1,9}$/', $amount) === 1)) || (int) $amount < 1) {
            throw new BadField('Amount');
        }
        $phone = $this->msisdn($b->scalar('PhoneNumber'), 'PhoneNumber');
        $this->msisdn($b->scalar('PartyA'), 'PartyA');
        $partyB = $b->string('PartyB');
        if (preg_match('/^\d{5,7}$/', $partyB) !== 1) {
            throw new BadField('PartyB');
        }
        $callback = $b->string('CallBackURL');
        if (!$this->isHttpUrl($callback)) {
            throw new BadField('CallBackURL');
        }
        $reference = $b->string('AccountReference');
        $description = $b->string('TransactionDesc');

        $now = $this->clock->now();
        $nowF = $this->scenarios->now();
        $merchantId = Ids::merchantRequestId();
        $checkoutId = Ids::checkoutRequestId($now);
        $this->store->insertTransaction([
            'kind' => 'stk',
            'status' => 'pending',
            'merchant_request_id' => $merchantId,
            'checkout_request_id' => $checkoutId,
            'shortcode' => $this->config->shortCode,
            'party_b' => $partyB,
            'txn_type' => $type,
            'amount_cents' => (int) $amount * 100,
            'msisdn' => $phone,
            'account_reference' => $reference,
            'description' => $description,
            'callback_url' => $callback,
            'created_at' => $nowF,
            'expires_at' => $nowF + $this->config->stkTimeoutSeconds,
        ]);

        return MockResponse::json(200, [
            'MerchantRequestID' => $merchantId,
            'CheckoutRequestID' => $checkoutId,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]);
    }

    private function stkQuery(Payload $b): MockResponse
    {
        $this->checkShortCodeAndPassword($b);
        $checkoutId = $b->string('CheckoutRequestID');
        $txn = $this->store->transactionByCheckoutId($checkoutId);
        if ($txn === null) {
            throw new BadField('CheckoutRequestID');
        }
        if ($txn->status === 'pending') {
            return $this->error(500, '500.001.1001', 'The transaction is being processed');
        }

        return MockResponse::json(200, [
            'ResponseCode' => '0',
            'ResponseDescription' => 'The service request has been accepted successfully',
            'MerchantRequestID' => $txn->merchantRequestId,
            'CheckoutRequestID' => $txn->checkoutRequestId,
            'ResultCode' => (string) ($txn->resultCode ?? 0),
            'ResultDesc' => $txn->resultDesc,
        ]);
    }

    private function c2bRegister(Payload $b): MockResponse
    {
        $shortCode = $b->string('ShortCode');
        if ($shortCode !== $this->config->shortCode) {
            throw new BadField('ShortCode');
        }
        $type = $b->string('ResponseType');
        if (!\in_array($type, ['Completed', 'Cancelled'], true)) {
            throw new BadField('ResponseType');
        }
        $confirmation = $b->string('ConfirmationURL');
        $validation = $b->string('ValidationURL');
        if (!$this->isHttpUrl($confirmation) || !$this->isHttpUrl($validation)) {
            throw new BadField('URL');
        }
        $this->store->saveC2bUrls($shortCode, $confirmation, $validation, $type);

        return MockResponse::json(200, [
            'OriginatorCoversationID' => Ids::originatorConversationId(),
            'ResponseCode' => '0',
            'ResponseDescription' => 'success',
        ]);
    }

    private function c2bSimulate(Payload $b): MockResponse
    {
        if ($b->string('ShortCode') !== $this->config->shortCode) {
            throw new BadField('ShortCode');
        }
        if ($b->string('CommandID') !== 'CustomerPayBillOnline') {
            throw new BadField('CommandID');
        }
        $amount = $b->scalar('Amount');
        if ((!\is_int($amount) && !(\is_string($amount) && preg_match('/^\d{1,9}$/', $amount) === 1)) || (int) $amount < 1) {
            throw new BadField('Amount');
        }
        $msisdn = $this->msisdn($b->scalar('Msisdn'), 'Msisdn');
        $ref = $b->string('BillRefNumber');
        $result = $this->simulateC2b((int) $amount, $msisdn, $ref);

        return MockResponse::json(200, [
            'OriginatorCoversationID' => $result['originatorConversationId'],
            'ConversationID' => $result['conversationId'],
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);
    }

    private function b2c(Payload $b): MockResponse
    {
        $b->string('InitiatorName');
        $b->string('SecurityCredential');
        if (!\in_array($b->string('CommandID'), ['SalaryPayment', 'BusinessPayment', 'PromotionPayment'], true)) {
            throw new BadField('CommandID');
        }
        $amount = $b->scalar('Amount');
        if ((!\is_int($amount) && !(\is_string($amount) && preg_match('/^\d{1,9}$/', $amount) === 1)) || (int) $amount < 1) {
            throw new BadField('Amount');
        }
        if ($b->string('PartyA') !== $this->config->shortCode) {
            throw new BadField('PartyA');
        }
        $msisdn = $this->msisdn($b->scalar('PartyB'), 'PartyB');
        $resultUrl = $b->string('ResultURL');
        if (!$this->isHttpUrl($resultUrl) || !$this->isHttpUrl($b->string('QueueTimeOutURL'))) {
            throw new BadField('ResultURL');
        }
        $originator = $b->optionalString('OriginatorConversationID') ?? Ids::originatorConversationId();
        $conversation = Ids::conversationId();

        $id = $this->store->insertTransaction([
            'kind' => 'b2c',
            'status' => 'success',
            'conversation_id' => $conversation,
            'originator_conversation_id' => $originator,
            'shortcode' => $this->config->shortCode,
            'party_b' => $msisdn,
            'txn_type' => $b->string('CommandID'),
            'amount_cents' => (int) $amount * 100,
            'msisdn' => $msisdn,
            'account_reference' => $b->looseString('Occasion'),
            'description' => $b->looseString('Remarks'),
            'callback_url' => $resultUrl,
            'result_code' => 0,
            'result_desc' => 'The service request is processed successfully.',
            'receipt' => Ids::receipt(),
            'created_at' => $this->scenarios->now(),
            'resolved_at' => $this->scenarios->now(),
        ]);
        $txn = $this->store->transaction($id);
        if ($txn !== null) {
            $payload = CallbackFactory::encode(CallbackFactory::b2cResult($txn, $this->clock->now()));
            $this->store->addDelivery($id, 'B2C result', $resultUrl, $payload, $this->scenarios->maxAttempts(), $this->scenarios->now());
        }

        return MockResponse::json(200, [
            'ConversationID' => $conversation,
            'OriginatorConversationID' => $originator,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);
    }

    private function transactionStatus(Payload $b): MockResponse
    {
        $b->string('Initiator');
        $b->string('SecurityCredential');
        if ($b->string('CommandID') !== 'TransactionStatusQuery') {
            throw new BadField('CommandID');
        }
        $receipt = $b->string('TransactionID');
        $resultUrl = $b->string('ResultURL');
        if (!$this->isHttpUrl($resultUrl) || !$this->isHttpUrl($b->string('QueueTimeOutURL'))) {
            throw new BadField('ResultURL');
        }
        $found = $this->store->transactionByReceipt($receipt);
        $conversation = Ids::conversationId();
        $originator = Ids::originatorConversationId();
        $payload = CallbackFactory::encode(CallbackFactory::statusResult($originator, $conversation, $found, $this->clock->now()));
        $this->store->addDelivery($found->id ?? 0, 'Transaction Status result', $resultUrl, $payload, $this->scenarios->maxAttempts(), $this->scenarios->now());

        return MockResponse::json(200, [
            'ConversationID' => $conversation,
            'OriginatorConversationID' => $originator,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);
    }

    private function reversal(Payload $b): MockResponse
    {
        $b->string('Initiator');
        $b->string('SecurityCredential');
        if ($b->string('CommandID') !== 'TransactionReversal') {
            throw new BadField('CommandID');
        }
        $receipt = $b->string('TransactionID');
        $resultUrl = $b->string('ResultURL');
        if (!$this->isHttpUrl($resultUrl) || !$this->isHttpUrl($b->string('QueueTimeOutURL'))) {
            throw new BadField('ResultURL');
        }
        $found = $this->store->transactionByReceipt($receipt);
        $ok = $found !== null && $found->status === 'success' && $found->kind !== 'b2c';
        if ($found !== null && $ok) {
            $this->store->updateTransaction($found->id, ['status' => 'reversed']);
        }
        $conversation = Ids::conversationId();
        $originator = Ids::originatorConversationId();
        $payload = CallbackFactory::encode(CallbackFactory::reversalResult($originator, $conversation, $ok, $receipt));
        $this->store->addDelivery($found->id ?? 0, 'Reversal result', $resultUrl, $payload, $this->scenarios->maxAttempts(), $this->scenarios->now());

        return MockResponse::json(200, [
            'ConversationID' => $conversation,
            'OriginatorConversationID' => $originator,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);
    }

    /**
     * @return array{originatorConversationId: string, conversationId: string, status: string, id: int}
     */
    public function simulateC2b(int $amount, string $msisdn, string $billRef): array
    {
        $now = $this->clock->now();
        $nowF = $this->scenarios->now();
        $conversation = Ids::conversationId();
        $originator = Ids::originatorConversationId();
        $urls = $this->store->c2bUrls($this->config->shortCode);

        $id = $this->store->insertTransaction([
            'kind' => 'c2b',
            'status' => 'pending',
            'conversation_id' => $conversation,
            'originator_conversation_id' => $originator,
            'shortcode' => $this->config->shortCode,
            'party_b' => $this->config->shortCode,
            'txn_type' => 'CustomerPayBillOnline',
            'amount_cents' => $amount * 100,
            'msisdn' => $msisdn,
            'account_reference' => $billRef,
            'receipt' => Ids::receipt(),
            'callback_url' => $urls['confirmation'] ?? '',
            'created_at' => $nowF,
        ]);
        $txn = $this->store->transaction($id);
        if ($txn === null) {
            throw new \LogicException('Transaction vanished after insert.');
        }

        $accepted = true;
        $reason = 'The service request is processed successfully.';
        if ($urls !== null && $urls['validation'] !== '') {
            $verdict = $this->askValidation($urls['validation'], CallbackFactory::encode(CallbackFactory::c2b($txn, $now)));
            if ($verdict === null) {
                $accepted = $urls['responseType'] === 'Completed';
                $reason = $accepted ? 'Validation URL unreachable; completed by default' : 'Validation URL unreachable; cancelled by default';
            } else {
                $accepted = $verdict['code'] === '0';
                $reason = $accepted ? $reason : 'Rejected by validation URL: ' . $verdict['code'] . ' ' . $verdict['desc'];
            }
        }

        if (!$accepted) {
            $this->store->updateTransaction($id, ['status' => 'rejected', 'result_code' => 1, 'result_desc' => $reason, 'receipt' => '', 'resolved_at' => $nowF]);

            return ['originatorConversationId' => $originator, 'conversationId' => $conversation, 'status' => 'rejected', 'id' => $id];
        }

        $this->store->updateTransaction($id, ['status' => 'success', 'result_code' => 0, 'result_desc' => $reason, 'resolved_at' => $nowF]);
        if ($urls !== null && $urls['confirmation'] !== '') {
            $done = $this->store->transaction($id);
            if ($done !== null) {
                $payload = CallbackFactory::encode(CallbackFactory::c2b($done, $now));
                $this->store->addDelivery($id, 'C2B confirmation', $urls['confirmation'], $payload, $this->scenarios->maxAttempts(), $nowF);
            }
        } else {
            $this->store->updateTransaction($id, ['callback_withheld' => 1]);
        }

        return ['originatorConversationId' => $originator, 'conversationId' => $conversation, 'status' => 'success', 'id' => $id];
    }

    /** @return array{code: string, desc: string}|null null when the URL could not be reached */
    private function askValidation(string $url, string $payload): ?array
    {
        try {
            $response = $this->http->sendRequest(new Request('POST', $url, ['Content-Type' => 'application/json', 'User-Agent' => 'daraja-mock/1'], new Stream($payload)));
            $decoded = Json::decodeObject((string) $response->getBody());
            $p = Payload::root($decoded);

            return ['code' => $p->string('ResultCode'), 'desc' => $p->looseString('ResultDesc')];
        } catch (ClientExceptionInterface|\JsonException|\Daraja\Exception\MalformedPayloadException) {
            return null;
        }
    }

    // ---------------------------------------------------------------- helpers

    private function checkShortCodeAndPassword(Payload $b): void
    {
        $shortCode = $b->string('BusinessShortCode');
        if ($shortCode !== $this->config->shortCode) {
            throw new BadField('BusinessShortCode');
        }
        $timestamp = $b->string('Timestamp');
        if (preg_match('/^\d{14}$/', $timestamp) !== 1) {
            throw new BadField('Timestamp');
        }
        $expected = StkPassword::generate($this->config->shortCode, $this->config->passKey, $timestamp);
        if (!hash_equals($expected, $b->string('Password'))) {
            throw new BadField('Password');
        }
    }

    private function msisdn(int|float|string|bool|null $value, string $field): string
    {
        $parsed = \is_int($value) || \is_string($value) ? Msisdn::tryParse((string) $value) : null;
        if ($parsed === null || !str_starts_with((string) $value, '254')) {
            throw new BadField($field);
        }

        return $parsed->value;
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1;
    }

    private function error(int $status, string $code, string $message): MockResponse
    {
        return MockResponse::json($status, [
            'requestId' => Ids::merchantRequestId(),
            'errorCode' => $code,
            'errorMessage' => $message,
        ]);
    }

    private function methodNotAllowed(): MockResponse
    {
        return $this->error(405, '405.001.01', 'Method not allowed');
    }

    // ---------------------------------------------------------------- console

    private function console(): MockResponse
    {
        $html = file_get_contents(__DIR__ . '/Console/index.html');

        return MockResponse::html($html === false ? 'Console file missing' : $html);
    }

    private function consoleApi(MockRequest $r): MockResponse
    {
        if (!$this->hostAllowed($r)) {
            return MockResponse::json(403, ['error' => 'Host not allowed. Open the console on localhost or 127.0.0.1.']);
        }
        $path = $r->path;

        if ($r->method === 'GET' && $path === '/console/api/state') {
            return MockResponse::json(200, $this->state());
        }
        if ($r->method !== 'POST') {
            return MockResponse::json(405, ['error' => 'Method not allowed']);
        }
        // Browsers cannot send application/json cross-site without a CORS preflight, which this server never grants.
        if (!str_starts_with(strtolower($r->header('content-type')), 'application/json')) {
            return MockResponse::json(415, ['error' => 'Send application/json']);
        }
        try {
            $body = $r->body === '' ? Payload::root([]) : Payload::root(Json::decodeObject($r->body));
        } catch (\JsonException) {
            return MockResponse::json(400, ['error' => 'Body must be a JSON object']);
        }

        try {
            if (preg_match('#^/console/api/stk/(\d+)/respond$#', $path, $m) === 1) {
                $action = $body->string('action');
                if (!\in_array($action, Scenarios::actions(), true)) {
                    return MockResponse::json(400, ['error' => 'Unknown action']);
                }
                $send = $body->scalar('sendCallback');
                $txn = $this->scenarios->resolveStk((int) $m[1], $action, $send !== false);

                return $txn === null
                    ? MockResponse::json(409, ['error' => 'This prompt was already answered or does not exist'])
                    : MockResponse::json(200, ['transaction' => $txn->toArray()]);
            }
            if (preg_match('#^/console/api/transactions/(\d+)/send-callback$#', $path, $m) === 1) {
                $txn = $this->store->transaction((int) $m[1]);
                if ($txn === null || $txn->status === 'pending') {
                    return MockResponse::json(409, ['error' => 'Nothing to send yet']);
                }
                if ($txn->kind === 'c2b') {
                    $urls = $this->store->c2bUrls($this->config->shortCode);
                    if ($urls === null || $urls['confirmation'] === '') {
                        return MockResponse::json(409, ['error' => 'No confirmation URL registered']);
                    }
                    $payload = CallbackFactory::encode(CallbackFactory::c2b($txn, $this->clock->now()));
                    $this->store->addDelivery($txn->id, 'C2B confirmation', $urls['confirmation'], $payload, $this->scenarios->maxAttempts(), $this->scenarios->now());
                    $this->store->updateTransaction($txn->id, ['callback_withheld' => 0]);
                } else {
                    $this->scenarios->enqueueStkCallback($txn);
                }

                return MockResponse::json(200, ['ok' => true]);
            }
            if (preg_match('#^/console/api/deliveries/(\d+)/redeliver$#', $path, $m) === 1) {
                $delivery = $this->store->delivery((int) $m[1]);
                if ($delivery === null) {
                    return MockResponse::json(404, ['error' => 'Unknown delivery']);
                }
                $this->store->requeueDelivery($delivery->id, $this->scenarios->maxAttempts(), $this->scenarios->now());

                return MockResponse::json(200, ['ok' => true]);
            }
            if ($path === '/console/api/c2b/simulate') {
                $amount = $body->int('amount');
                $msisdn = Msisdn::tryParse($body->string('msisdn'));
                $ref = $body->string('billRef');
                if ($amount < 1 || $msisdn === null || mb_strlen($ref) > 20) {
                    return MockResponse::json(400, ['error' => 'Check amount (whole KES, at least 1), phone and bill reference (max 20 characters)']);
                }

                return MockResponse::json(200, $this->simulateC2b($amount, $msisdn->value, $ref));
            }
            if ($path === '/console/api/stk/new') {
                $amount = $body->int('amount');
                $msisdn = Msisdn::tryParse($body->string('msisdn'));
                $callback = $body->looseString('callbackUrl');
                if ($amount < 1 || $msisdn === null || ($callback !== '' && !$this->isHttpUrl($callback))) {
                    return MockResponse::json(400, ['error' => 'Check amount, phone and callback URL']);
                }
                $nowF = $this->scenarios->now();
                $id = $this->store->insertTransaction([
                    'kind' => 'stk',
                    'status' => 'pending',
                    'merchant_request_id' => Ids::merchantRequestId(),
                    'checkout_request_id' => Ids::checkoutRequestId($this->clock->now()),
                    'shortcode' => $this->config->shortCode,
                    'party_b' => $this->config->shortCode,
                    'txn_type' => 'CustomerPayBillOnline',
                    'amount_cents' => $amount * 100,
                    'msisdn' => $msisdn->value,
                    'account_reference' => 'CONSOLE-TEST',
                    'description' => 'Console test',
                    'callback_url' => $callback,
                    'created_at' => $nowF,
                    'expires_at' => $nowF + $this->config->stkTimeoutSeconds,
                ]);

                return MockResponse::json(200, ['id' => $id]);
            }
            if ($path === '/console/api/reset') {
                $this->store->reset();

                return MockResponse::json(200, ['ok' => true]);
            }
        } catch (\Daraja\Exception\MalformedPayloadException $e) {
            return MockResponse::json(400, ['error' => $e->getMessage()]);
        }

        return MockResponse::json(404, ['error' => 'Unknown console endpoint']);
    }

    private function hostAllowed(MockRequest $r): bool
    {
        $allowed = ['localhost', '127.0.0.1', '[::1]'];
        $baseHost = parse_url($this->config->baseUrl, PHP_URL_HOST);
        if (\is_string($baseHost) && $baseHost !== '') {
            $allowed[] = $baseHost;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', $r->header('host')));
        if (!\in_array($host, $allowed, true)) {
            return false;
        }
        $origin = $r->header('origin');
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            $hostHeader = strtolower($r->header('host'));
            $originAuthority = strtolower((\is_string($originHost) ? $originHost : '') . (\is_int($originPort) ? ':' . $originPort : ''));
            if ($originAuthority !== $hostHeader) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $transactions = $this->store->latestTransactions(40);
        $deliveries = $this->store->latestDeliveries(120);
        $byTxn = [];
        foreach ($deliveries as $d) {
            $byTxn[$d->transactionId][] = $d->toArray();
        }

        $rows = [];
        foreach ($transactions as $t) {
            $row = $t->toArray();
            $row['deliveries'] = $byTxn[$t->id] ?? [];
            $rows[] = $row;
        }
        $orphans = [];
        foreach ($deliveries as $d) {
            if ($d->transactionId === 0 || !\in_array($d->transactionId, array_map(static fn (Txn $t): int => $t->id, $transactions), true)) {
                $orphans[] = $d->toArray();
            }
        }

        $wire = [];
        foreach ($this->store->latestWire(40) as $w) {
            $wire[] = [
                'id' => $w['id'] ?? 0,
                'at' => $w['at'] ?? 0,
                'method' => $w['method'] ?? '',
                'path' => $w['path'] ?? '',
                'status' => $w['status'] ?? 0,
                'ms' => $w['ms'] ?? 0,
                'request' => $w['request_body'] ?? '',
                'response' => $w['response_body'] ?? '',
            ];
        }

        $pending = array_map(static fn (Txn $t): array => $t->toArray(), $this->store->pendingStk());

        return [
            'serverTime' => $this->scenarios->now(),
            'config' => [
                'baseUrl' => $this->config->baseUrl,
                'shortCode' => $this->config->shortCode,
                'consumerKey' => $this->config->consumerKey,
                'consumerSecret' => $this->config->consumerSecret,
                'passKey' => $this->config->passKey,
                'stkTimeoutSeconds' => $this->config->stkTimeoutSeconds,
                'retryDelays' => $this->config->retryDelays,
            ],
            'pending' => $pending,
            'transactions' => $rows,
            'orphanDeliveries' => $orphans,
            'wire' => $wire,
        ];
    }
}
