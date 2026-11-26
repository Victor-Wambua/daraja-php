<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Callback\C2bTransaction;
use Daraja\Callback\CallbackParser;
use Daraja\Callback\ResultCallback;
use Daraja\Callback\StkCallback;
use Daraja\Exception\PaymentMismatchException;
use Daraja\Exception\UntrustedSourceException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One safe entry point for inbound callbacks. In order, it:
 *
 *  1. checks the source IP against your allow-list (if you configured one),
 *  2. parses the body strictly (malformed payloads throw, nothing is half-trusted),
 *  3. cross-checks amount / phone / reference against the request you originally made (if you supply it),
 *  4. claims the callback in the idempotency store, so a retry or duplicate never runs your handler twice,
 *  5. runs your handler, then marks the callback done. If the handler throws, the claim is released and the exception propagates,
 *     so a later delivery (or your own retry) can try again.
 */
final readonly class CallbackProcessor
{
    public function __construct(
        private IdempotencyStore $store,
        private ?IpAllowList $allowList = null,
        private LoggerInterface $logger = new NullLogger(),
        private int $lockSeconds = 300,
    ) {
    }

    /**
     * @param callable(StkCallback): void                                    $handler
     * @param ExpectedPayment|callable(StkCallback): ?ExpectedPayment|null   $expected pass a lookup function that finds your
     *                                                                                  order by CheckoutRequestID. Returning null means
     *                                                                                  "we never sent this request" and the callback is rejected.
     */
    public function processStk(string $rawBody, callable $handler, ExpectedPayment|callable|null $expected = null, ?string $sourceIp = null): ProcessedCallback
    {
        $this->assertSource($sourceIp);
        $callback = CallbackParser::parseStk($rawBody);

        if ($expected !== null) {
            $order = $expected instanceof ExpectedPayment ? $expected : $expected($callback);
            if ($order === null) {
                throw new PaymentMismatchException(['no originating request found for CheckoutRequestID ' . $callback->checkoutRequestId]);
            }
            PaymentCrossCheck::verifyStk($callback, $order);
        }

        return $this->once('stk:' . $callback->checkoutRequestId, fn () => $handler($callback));
    }

    /**
     * @param callable(C2bTransaction): void                                    $handler
     * @param ExpectedPayment|callable(C2bTransaction): ?ExpectedPayment|null   $expected
     */
    public function processC2bConfirmation(string $rawBody, callable $handler, ExpectedPayment|callable|null $expected = null, ?string $sourceIp = null): ProcessedCallback
    {
        $this->assertSource($sourceIp);
        $payment = CallbackParser::parseC2b($rawBody);

        if ($expected !== null) {
            $order = $expected instanceof ExpectedPayment ? $expected : $expected($payment);
            if ($order === null) {
                throw new PaymentMismatchException(['no expected payment found for bill reference ' . $payment->billRefNumber]);
            }
            PaymentCrossCheck::verifyC2b($payment, $order);
        }

        return $this->once('c2b:' . $payment->transId, fn () => $handler($payment));
    }

    /**
     * For B2C, Transaction Status and Reversal results.
     *
     * @param callable(ResultCallback): void $handler
     */
    public function processResult(string $rawBody, callable $handler, ?string $sourceIp = null): ProcessedCallback
    {
        $this->assertSource($sourceIp);
        $result = CallbackParser::parseResult($rawBody);

        return $this->once('result:' . $result->conversationId, fn () => $handler($result));
    }

    private function assertSource(?string $sourceIp): void
    {
        if ($this->allowList !== null && !$this->allowList->isAllowed($sourceIp)) {
            $this->logger->warning('Callback rejected: source address not on the allow-list', ['ip' => $sourceIp]);
            throw new UntrustedSourceException('Callback source address is not on the allow-list.');
        }
    }

    private function once(string $key, \Closure $work): ProcessedCallback
    {
        $begin = $this->store->begin($key, $this->lockSeconds);
        if ($begin === BeginResult::AlreadyProcessed) {
            $this->logger->info('Duplicate callback ignored', ['key' => $key]);

            return new ProcessedCallback(ProcessOutcome::Duplicate, $key);
        }
        if ($begin === BeginResult::InProgress) {
            $this->logger->info('Callback already being processed', ['key' => $key]);

            return new ProcessedCallback(ProcessOutcome::InProgress, $key);
        }

        try {
            $work();
        } catch (\Throwable $e) {
            $this->store->release($key);
            throw $e;
        }
        $this->store->complete($key);

        return new ProcessedCallback(ProcessOutcome::Handled, $key);
    }
}
