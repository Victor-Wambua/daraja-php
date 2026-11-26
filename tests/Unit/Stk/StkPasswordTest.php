<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Stk;

use Daraja\Stk\StkPassword;
use PHPUnit\Framework\TestCase;

final class StkPasswordTest extends TestCase
{
    public function testPasswordIsBase64OfShortcodePasskeyTimestamp(): void
    {
        $password = StkPassword::generate('174379', 'passkey', '20260115123045');
        self::assertSame('174379passkey20260115123045', base64_decode($password, true));
    }

    public function testTimestampIsEastAfricaTime(): void
    {
        // 09:30:45 UTC is 12:30:45 in Nairobi.
        $utc = new \DateTimeImmutable('2026-01-15 09:30:45', new \DateTimeZone('UTC'));
        self::assertSame('20260115123045', StkPassword::timestamp($utc));
    }

    public function testTimestampRollsOverTheDate(): void
    {
        $utc = new \DateTimeImmutable('2026-01-15 22:30:00', new \DateTimeZone('UTC'));
        self::assertSame('20260116013000', StkPassword::timestamp($utc));
    }

    public function testTimestampHasFourteenDigits(): void
    {
        self::assertMatchesRegularExpression('/^\d{14}$/', StkPassword::timestamp(new \DateTimeImmutable()));
    }
}
