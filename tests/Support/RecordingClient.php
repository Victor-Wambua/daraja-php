<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

use Daraja\Http\Psr7\Response;
use Daraja\Http\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A scripted PSR-18 client: replies from a queue and remembers every request. No network. */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue;

    public function __construct(ResponseInterface|\Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function enqueue(ResponseInterface|\Throwable ...$more): void
    {
        array_push($this->queue, ...$more);
    }

    public static function json(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], new Stream($body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('RecordingClient has no more scripted responses for ' . $request->getMethod() . ' ' . $request->getUri());
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return array<mixed> */
    public function lastJsonBody(): array
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No requests recorded');
        }
        $data = json_decode((string) $last->getBody(), true, 32, JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        return $data;
    }

    public function last(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No requests recorded');
        }

        return $last;
    }
}
