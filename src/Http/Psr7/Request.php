<?php

declare(strict_types=1);

namespace Daraja\Http\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Request implements RequestInterface
{
    use MessageTrait;

    private string $method;
    private UriInterface $uri;
    private ?string $requestTarget = null;

    /** @param array<string, string|array<string>> $headers */
    public function __construct(string $method, UriInterface|string $uri, array $headers = [], ?StreamInterface $body = null)
    {
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->body = $body ?? new Stream();
        $this->initHeaders($headers);
        if ($this->uri->getHost() !== '' && !$this->hasHeader('Host')) {
            $port = $this->uri->getPort();
            $this->setHeader('Host', $this->uri->getHost() . ($port !== null ? ':' . $port : ''));
        }
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost || !$new->hasHeader('Host')) {
            if ($uri->getHost() !== '') {
                $port = $uri->getPort();
                $new->setHeader('Host', $uri->getHost() . ($port !== null ? ':' . $port : ''));
            }
        }

        return $new;
    }
}
