<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Http\Psr7\Request;
use Daraja\Http\Psr7\Stream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * The mock's background worker. Each tick it (1) times out STK prompts nobody answered and
 * (2) delivers due callbacks, retrying failures on the configured schedule.
 *
 * Retrying is a test aid: it lets you prove your endpoint survives duplicate and late deliveries.
 * It is not a statement about how often or how long Safaricom itself retries; that is not
 * something this project has verified.
 */
final readonly class Dispatcher
{
    public function __construct(
        private Store $store,
        private ClientInterface $http,
        private MockConfig $config,
        private Scenarios $scenarios,
    ) {
    }

    /** @return int number of delivery attempts made */
    public function tick(): int
    {
        $now = $this->scenarios->now();
        foreach ($this->store->expiredPendingStk($now) as $txn) {
            $this->scenarios->resolveStk($txn->id, 'timeout');
        }

        $attempts = 0;
        foreach ($this->store->dueDeliveries($now) as $delivery) {
            // Lease the delivery so a second worker does not send it at the same moment.
            if (!$this->store->claimDelivery($delivery->id, $now, $this->config->callbackTimeoutSeconds + 5)) {
                continue;
            }
            $this->attempt($delivery);
            ++$attempts;
        }

        return $attempts;
    }

    private function attempt(Delivery $delivery): void
    {
        $request = new Request(
            'POST',
            $delivery->url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'daraja-mock/1'],
            new Stream($delivery->payload),
        );

        $status = null;
        $error = '';
        try {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();
        } catch (ClientExceptionInterface $e) {
            $error = $e->getMessage();
        }

        if ($status !== null && $status >= 200 && $status < 300) {
            $this->store->markDelivered($delivery->id, $status, $this->scenarios->now());

            return;
        }
        if ($status !== null) {
            $error = 'HTTP ' . $status;
        }

        $attemptNumber = $delivery->attempts + 1;
        $retryAt = null;
        if ($attemptNumber < $delivery->maxAttempts) {
            $delays = $this->config->retryDelays;
            $delay = $delays[min($attemptNumber, \count($delays)) - 1] ?? 1.0;
            $retryAt = $this->scenarios->now() + $delay;
        }
        $this->store->markAttemptFailed($delivery->id, $status, $error, $retryAt);
    }
}
