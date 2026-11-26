<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Stk;

use Daraja\Stk\StkOutcome;
use Daraja\Stk\StkResultCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StkResultCodeTest extends TestCase
{
    /** @return iterable<string, array{int, StkOutcome}> */
    public static function mapping(): iterable
    {
        yield '0 success' => [0, StkOutcome::Success];
        yield '1 insufficient funds' => [1, StkOutcome::InsufficientFunds];
        yield '1032 cancelled' => [1032, StkOutcome::Cancelled];
        yield '1037 timeout' => [1037, StkOutcome::TimedOut];
        yield '1019 expired' => [1019, StkOutcome::TimedOut];
        yield '2001 wrong pin' => [2001, StkOutcome::WrongPin];
        yield '1001 in progress' => [1001, StkOutcome::Failed];
        yield '1025 push error' => [1025, StkOutcome::Failed];
    }

    #[DataProvider('mapping')]
    public function testMapsCodesToOutcomes(int $code, StkOutcome $outcome): void
    {
        $result = StkResultCode::tryFromCode($code);
        self::assertNotNull($result);
        self::assertSame($outcome, $result->outcome());
        self::assertNotSame('', $result->description());
    }

    public function testUnknownCodeIsNotAnError(): void
    {
        self::assertNull(StkResultCode::tryFromCode(9999));
    }

    public function testOnlySuccessCannotBeRetried(): void
    {
        self::assertFalse(StkOutcome::Success->canRetry());
        self::assertTrue(StkOutcome::Cancelled->canRetry());
    }
}
