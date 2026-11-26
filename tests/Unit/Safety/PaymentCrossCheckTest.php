<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Callback\CallbackParser;
use Daraja\Exception\PaymentMismatchException;
use Daraja\Safety\ExpectedPayment;
use Daraja\Safety\PaymentCrossCheck;
use Daraja\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PaymentCrossCheckTest extends TestCase
{
    public function testMatchingStkPaymentPasses(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_success'));
        PaymentCrossCheck::verifyStk($cb, ExpectedPayment::kes(1, '0700000001'));
        $this->addToAssertionCount(1);
    }

    public function testUnderpaymentIsCaught(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_success'));
        try {
            PaymentCrossCheck::verifyStk($cb, ExpectedPayment::kes(500));
            self::fail('expected mismatch');
        } catch (PaymentMismatchException $e) {
            self::assertStringContainsString('KES 1.00', $e->getMessage());
            self::assertStringContainsString('KES 500.00', $e->getMessage());
            self::assertCount(1, $e->problems);
        }
    }

    public function testDifferentPayerPhoneIsCaught(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_success'));
        $this->expectException(PaymentMismatchException::class);
        PaymentCrossCheck::verifyStk($cb, ExpectedPayment::kes(1, '0700000999'));
    }

    public function testEveryProblemIsReported(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_success'));
        try {
            PaymentCrossCheck::verifyStk($cb, ExpectedPayment::kes(2, '0700000999'));
            self::fail('expected mismatch');
        } catch (PaymentMismatchException $e) {
            self::assertCount(2, $e->problems);
        }
    }

    public function testFailedCallbacksAreNotCompared(): void
    {
        $cb = CallbackParser::parseStk(Fixtures::raw('stk_callback_cancelled'));
        PaymentCrossCheck::verifyStk($cb, ExpectedPayment::kes(9999));
        $this->addToAssertionCount(1);
    }

    public function testC2bAmountAndBillReference(): void
    {
        $tx = CallbackParser::parseC2b(Fixtures::raw('c2b_confirmation'));
        PaymentCrossCheck::verifyC2b($tx, ExpectedPayment::kes(10, null, 'invoice008'));
        $this->addToAssertionCount(1);

        $this->expectException(PaymentMismatchException::class);
        PaymentCrossCheck::verifyC2b($tx, ExpectedPayment::kes(10, null, 'invoice999'));
    }

    public function testC2bWrongAmount(): void
    {
        $tx = CallbackParser::parseC2b(Fixtures::raw('c2b_confirmation'));
        $this->expectException(PaymentMismatchException::class);
        PaymentCrossCheck::verifyC2b($tx, ExpectedPayment::kes(11, null, 'invoice008'));
    }

    public function testC2bPhoneIsOnlyComparedWhenUnmasked(): void
    {
        $masked = CallbackParser::parseC2b(str_replace('254700000001', '25470****001', Fixtures::raw('c2b_confirmation')));
        PaymentCrossCheck::verifyC2b($masked, ExpectedPayment::kes(10, '0700000777', 'invoice008'));
        $this->addToAssertionCount(1);

        $plain = CallbackParser::parseC2b(Fixtures::raw('c2b_confirmation'));
        $this->expectException(PaymentMismatchException::class);
        PaymentCrossCheck::verifyC2b($plain, ExpectedPayment::kes(10, '0700000777', 'invoice008'));
    }
}
