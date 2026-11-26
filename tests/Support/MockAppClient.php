<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

use Daraja\Http\Psr7\Response;
use Daraja\Http\Psr7\Stream;
use Daraja\Mock\MockApp;
use Daraja\Mock\MockRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that calls the mock server's code directly, in-process. No sockets. */
final class MockAppClient implements ClientInterface
{
    public function __construct(private readonly MockApp $app)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $response = $this->app->handle(new MockRequest(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $headers,
            (string) $request->getBody(),
        ));

        return new Response($response->status, $response->headers, new Stream($response->body));
    }
}
