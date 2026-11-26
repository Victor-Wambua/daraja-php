<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Exception\MalformedPayloadException;
use Daraja\Support\EatTime;
use PHPUnit\Framework\TestCase;

final class EatTimeTest extends TestCase
{
    public function testParsesAsNairobiTime(): void
    {
        $t = EatTime::parse('20191219102115');
        self::assertSame('2019-12-19T10:21:15+03:00', $t->format('c'));
        self::assertSame('07:21:15', $t->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s'));
    }

    public function testRejectsImpossibleDates(): void
    {
        $this->expectException(MalformedPayloadException::class);
        EatTime::parse('20191340102115');
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(MalformedPayloadException::class);
        EatTime::parse('yesterday');
    }
}
