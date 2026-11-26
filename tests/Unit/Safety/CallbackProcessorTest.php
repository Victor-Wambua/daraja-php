<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Callback\C2bTransaction;
use Daraja\Callback\ResultCallback;
use Daraja\Callback\StkCallback;
use Daraja\Exception\InvalidCallbackPayloadException;
use Daraja\Exception\PaymentMismatchException;
use Daraja\Exception\UntrustedSourceException;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\ExpectedPayment;
use Daraja\Safety\InMemoryIdempotencyStore;
use Daraja\Safety\IpAllowList;
use Daraja\Safety\ProcessOutcome;
use Daraja\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class CallbackProcessorTest extends TestCase
{
    private function processor(?IpAllowList $allow = null): CallbackProcessor
    {
        return new CallbackProcessor(new InMemoryIdempotencyStore(), $allow);
    }

    public function testFirstDeliveryRunsTheHandlerOnce(): void
    {
        $seen = [];
        $result = $this->processor()->processStk(Fixtures::raw('stk_callback_success'), static function (StkCallback $cb) use (&$seen): void {
            $seen[] = $cb->mpesaReceiptNumber;
        });
        self::assertSame(ProcessOutcome::Handled, $result->outcome);
        self::assertSame('stk:ws_CO_191220191020363925', $result->key);
        self::assertSame(['NLJ7RT61SV'], $seen);
    }

    public function testRetriedDeliveriesNeverRunTheHandlerAgain(): void
    {
        $processor = $this->processor();
        $credits = 0;
        $handler = static function () use (&$credits): void {
            ++$credits;
        };

        $outcomes = [];
        for ($i = 0; $i < 6; ++$i) {
            $outcomes[] = $processor->processStk(Fixtures::raw('stk_callback_success'), $handler)->outcome;
        }

        self::assertSame(1, $credits, 'six deliveries of the same callback must credit exactly once');
        self::assertSame(ProcessOutcome::Handled, $outcomes[0]);
        self::assertSame(array_fill(0, 5, ProcessOutcome::Duplicate), \array_slice($outcomes, 1));
    }

    public function testAcknowledgementIsAlwaysAnAcceptance(): void
    {
        $result = $this->processor()->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
        });
        self::assertSame(['ResultCode' => 0, 'ResultDesc' => 'Accepted'], $result->acknowledgement());
        self::assertSame('{"ResultCode":0,"ResultDesc":"Accepted"}', $result->acknowledgementJson());
    }

    public function testAFailingHandlerReleasesTheKeySoALaterDeliveryCanSucceed(): void
    {
        $processor = $this->processor();
        $attempts = 0;
        $flaky = static function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new \RuntimeException('database is down');
            }
        };

        try {
            $processor->processStk(Fixtures::raw('stk_callback_success'), $flaky);
            self::fail('expected the handler failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('database is down', $e->getMessage());
        }

        $second = $processor->processStk(Fixtures::raw('stk_callback_success'), $flaky);
        self::assertSame(ProcessOutcome::Handled, $second->outcome);
        self::assertSame(2, $attempts);
    }

    public function testADeliveryArrivingWhileAnotherIsInFlightIsNotProcessed(): void
    {
        $processor = $this->processor();
        $inner = null;
        $processor->processStk(Fixtures::raw('stk_callback_success'), function () use ($processor, &$inner): void {
            // re-entrant delivery while the first handler is still running
            $inner = $processor->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
                self::fail('must not run');
            });
        });
        self::assertNotNull($inner);
        self::assertSame(ProcessOutcome::InProgress, $inner->outcome);
    }

    public function testMalformedPayloadsDoNotClaimAKey(): void
    {
        $processor = $this->processor();
        try {
            $processor->processStk('{"Body":{}}', static function (): void {
            });
            self::fail('expected exception');
        } catch (InvalidCallbackPayloadException) {
            $this->addToAssertionCount(1);
        }
        $ok = $processor->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
        });
        self::assertSame(ProcessOutcome::Handled, $ok->outcome);
    }

    public function testSourceAddressMustBeOnTheAllowList(): void
    {
        $processor = $this->processor(new IpAllowList(['203.0.113.10']));
        $handler = static function (): void {
            self::fail('handler must not run');
        };
        foreach ([null, '198.51.100.7', 'garbage'] as $ip) {
            try {
                $processor->processStk(Fixtures::raw('stk_callback_success'), $handler, null, $ip);
                self::fail('expected UntrustedSourceException');
            } catch (UntrustedSourceException) {
                $this->addToAssertionCount(1);
            }
        }
        $ran = false;
        $processor->processStk(Fixtures::raw('stk_callback_success'), static function () use (&$ran): void {
            $ran = true;
        }, null, '203.0.113.10');
        self::assertTrue($ran);
    }

    public function testAmountMismatchIsRejectedBeforeAnythingIsClaimed(): void
    {
        $processor = $this->processor();
        try {
            $processor->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
                self::fail('handler must not run for a short payment');
            }, ExpectedPayment::kes(1500));
            self::fail('expected mismatch');
        } catch (PaymentMismatchException) {
            $this->addToAssertionCount(1);
        }
        // a correct delivery of the same callback id is still processed afterwards
        $ok = $processor->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
        }, ExpectedPayment::kes(1));
        self::assertSame(ProcessOutcome::Handled, $ok->outcome);
    }

    public function testExpectationLookupByCheckoutRequestId(): void
    {
        $orders = ['ws_CO_191220191020363925' => ExpectedPayment::kes(1, '0700000001')];
        $lookup = static fn (StkCallback $cb): ?ExpectedPayment => $orders[$cb->checkoutRequestId] ?? null;

        $ok = $this->processor()->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
        }, $lookup);
        self::assertSame(ProcessOutcome::Handled, $ok->outcome);
    }

    public function testACallbackForAnUnknownCheckoutRequestIsRejected(): void
    {
        $this->expectException(PaymentMismatchException::class);
        $this->expectExceptionMessage('no originating request');
        $this->processor()->processStk(Fixtures::raw('stk_callback_success'), static function (): void {
        }, static fn (StkCallback $cb): ?ExpectedPayment => null);
    }

    public function testFailedCallbacksAreProcessedToo(): void
    {
        $outcome = null;
        $this->processor()->processStk(Fixtures::raw('stk_callback_cancelled'), static function (StkCallback $cb) use (&$outcome): void {
            $outcome = $cb->outcome();
        });
        self::assertNotNull($outcome);
        self::assertSame('cancelled', $outcome->value);
    }

    public function testC2bConfirmationsAreDeduplicatedByTransId(): void
    {
        $processor = $this->processor();
        $count = 0;
        $handler = static function (C2bTransaction $tx) use (&$count): void {
            ++$count;
        };
        $processor->processC2bConfirmation(Fixtures::raw('c2b_confirmation'), $handler, ExpectedPayment::kes(10, null, 'invoice008'));
        $dup = $processor->processC2bConfirmation(Fixtures::raw('c2b_confirmation'), $handler, ExpectedPayment::kes(10, null, 'invoice008'));
        self::assertSame(1, $count);
        self::assertSame(ProcessOutcome::Duplicate, $dup->outcome);
        self::assertSame('c2b:RKTQDM7W6S', $dup->key);
    }

    public function testResultCallbacksAreDeduplicatedByConversationId(): void
    {
        $processor = $this->processor();
        $count = 0;
        $handler = static function (ResultCallback $r) use (&$count): void {
            ++$count;
        };
        $processor->processResult(Fixtures::raw('b2c_result'), $handler);
        $dup = $processor->processResult(Fixtures::raw('b2c_result'), $handler);
        self::assertSame(1, $count);
        self::assertSame('result:AG_20191219_00004e48cf7e3533f581', $dup->key);
    }
}
