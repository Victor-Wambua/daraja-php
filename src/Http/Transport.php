<?php

declare(strict_types=1);

namespace Daraja\Http;

use Daraja\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends one request, turns PSR-18 failures into TransportException and writes a single
 * log line per call. Headers and bodies are never logged: they carry credentials and phone numbers.
 *
 * @internal
 */
final readonly class Transport
{
    public function __construct(private ClientInterface $client, private LoggerInterface $logger)
    {
    }

    public function send(RequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        $path = $request->getUri()->getPath();
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Daraja request failed', ['method' => $request->getMethod(), 'path' => $path, 'error' => $e->getMessage()]);
            throw new TransportException('Could not reach Daraja: ' . $e->getMessage(), 0, $e);
        }
        $this->logger->debug('Daraja request', [
            'method' => $request->getMethod(),
            'path' => $path,
            'status' => $response->getStatusCode(),
            'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        return $response;
    }
}
