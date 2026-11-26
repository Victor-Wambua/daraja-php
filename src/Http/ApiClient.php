<?php

declare(strict_types=1);

namespace Daraja\Http;

use Daraja\Auth\TokenProvider;
use Daraja\Config\DarajaConfig;
use Daraja\Exception\ApiException;
use Daraja\Exception\AuthenticationException;
use Daraja\Exception\MalformedPayloadException;
use Daraja\Http\Psr7\Request;
use Daraja\Http\Psr7\Stream;
use Daraja\Support\Json;
use Daraja\Support\Payload;

/**
 * Authenticated JSON POSTs to Daraja.
 *
 * A 401 means the cached token is no longer accepted, so the token is dropped and the call is
 * repeated exactly once. No other status is retried: replaying a payment request after a timeout
 * can charge a customer twice, so that decision is left to the caller.
 *
 * @internal
 */
final readonly class ApiClient
{
    public function __construct(
        private DarajaConfig $config,
        private Transport $transport,
        private TokenProvider $tokens,
    ) {
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): Payload
    {
        $json = Json::encode($body);
        for ($attempt = 1; ; ++$attempt) {
            $request = new Request(
                'POST',
                $this->config->baseUrl . $path,
                [
                    'Authorization' => 'Bearer ' . $this->tokens->token(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                new Stream($json),
            );
            $response = $this->transport->send($request);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();

            if ($status === 401 && $attempt === 1) {
                $this->tokens->invalidate();
                continue;
            }
            if ($status === 401) {
                $e = ApiException::fromResponse($status, $raw);
                throw new AuthenticationException($e->getMessage(), $status, $e->errorCode, $e->errorMessage, $e->requestId, $e->rawBody);
            }
            if ($status < 200 || $status >= 300) {
                throw ApiException::fromResponse($status, $raw);
            }

            try {
                return Payload::root(Json::decodeObject($raw));
            } catch (\JsonException $e) {
                throw new MalformedPayloadException('Daraja returned a body that is not a JSON object (HTTP ' . $status . ').', 0, $e);
            }
        }
    }

    /**
     * Daraja signals some failures with HTTP 200 and a non-zero ResponseCode. Turn those into exceptions.
     */
    public static function assertAccepted(Payload $payload, int $status = 200): void
    {
        $code = $payload->string('ResponseCode');
        if ($code !== '0') {
            throw ApiException::fromResponseCode($code, $payload->looseString('ResponseDescription'), $status);
        }
    }
}
