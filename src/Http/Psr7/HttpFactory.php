<?php

declare(strict_types=1);

namespace Daraja\Http\Psr7;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/** One object implementing the four PSR-17 factories this library needs. */
final class HttpFactory implements RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface, UriFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, $reasonPhrase);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('Unable to read ' . $filename);
        }

        return new Stream($content);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        $content = stream_get_contents($resource);

        return new Stream($content === false ? '' : $content);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
