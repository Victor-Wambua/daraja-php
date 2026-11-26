<?php

declare(strict_types=1);

namespace Daraja\Http;

use Daraja\Exception\ConfigurationException;
use Daraja\Http\Psr7\Response;
use Daraja\Http\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A small PSR-18 client on top of ext-curl. TLS verification stays on, redirects are
 * not followed and responses are capped at 2 MiB. Use Guzzle or Symfony HttpClient
 * instead if you already have one; anything PSR-18 works.
 */
final class CurlClient implements ClientInterface
{
    private const MAX_RESPONSE_BYTES = 2_097_152;

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
        if (!\extension_loaded('curl')) {
            throw new ConfigurationException('The bundled CurlClient needs ext-curl. Install it or pass another PSR-18 client.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $responseHeaders = [];
        $status = 0;
        $tooLarge = false;

        $body = (string) $request->getBody();
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
        ];
        curl_setopt_array($handle, $options);
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        if ($request->getMethod() === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        $received = '';
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders, &$status): int {
            $length = \strlen($line);
            $line = trim($line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[trim($name)][] = trim($value);
            }

            return $length;
        });
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$received, &$tooLarge): int {
            if (\strlen($received) + \strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                $tooLarge = true;

                return 0;
            }
            $received .= $chunk;

            return \strlen($chunk);
        });

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new CurlException($request, 'Response body exceeded the 2 MiB limit.');
        }
        if ($ok === false || $errno !== 0) {
            throw new CurlException($request, \sprintf('cURL error %d: %s', $errno, $error));
        }

        return new Response($status === 0 ? 200 : $status, $responseHeaders, new Stream($received));
    }
}
