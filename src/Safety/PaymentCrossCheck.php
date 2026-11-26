<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Callback\C2bTransaction;
use Daraja\Callback\StkCallback;
use Daraja\Exception\PaymentMismatchException;

/** Compares a callback with the request that should have produced it. */
final class PaymentCrossCheck
{
    /**
     * Only meaningful for successful callbacks: a failed one carries no amount to compare.
     *
     * @throws PaymentMismatchException
     */
    public static function verifyStk(StkCallback $callback, ExpectedPayment $expected): void
    {
        if (!$callback->isSuccessful()) {
            return;
        }
        $problems = [];
        if ($callback->amount === null || !$callback->amount->equals($expected->amount)) {
            $problems[] = \sprintf('paid amount %s, expected %s', $callback->amount ?? 'none', $expected->amount);
        }
        if ($expected->phoneNumber !== null && ($callback->phoneNumber === null || !$callback->phoneNumber->equals($expected->phoneNumber))) {
            $problems[] = 'payer phone number differs from the one the prompt was sent to';
        }
        if ($problems !== []) {
            throw new PaymentMismatchException($problems);
        }
    }

    /**
     * Compares amount and bill reference. The payer's MSISDN is only compared when it arrives unmasked.
     *
     * @throws PaymentMismatchException
     */
    public static function verifyC2b(C2bTransaction $payment, ExpectedPayment $expected): void
    {
        $problems = [];
        if (!$payment->amount->equals($expected->amount)) {
            $problems[] = \sprintf('paid amount %s, expected %s', $payment->amount, $expected->amount);
        }
        if ($expected->accountReference !== null && !hash_equals($expected->accountReference, $payment->billRefNumber)) {
            $problems[] = 'bill reference differs from the expected account';
        }
        if ($expected->phoneNumber !== null && !str_contains($payment->msisdn, '*') && preg_match('/^\d{12}$/', $payment->msisdn) === 1
            && $payment->msisdn !== $expected->phoneNumber->value) {
            $problems[] = 'payer phone number differs from the expected payer';
        }
        if ($problems !== []) {
            throw new PaymentMismatchException($problems);
        }
    }
}
