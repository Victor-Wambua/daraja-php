<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Exception\MalformedPayloadException;
use Daraja\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testWholeShillings(): void
    {
        $m = Money::kes(1500);
        self::assertSame(150000, $m->cents);
        self::assertSame(1500, $m->shillings());
        self::assertTrue($m->isWholeShillings());
        self::assertSame('KES 1,500.00', (string) $m);
    }

    /** @return iterable<string, array{int|float|string, int}> */
    public static function wire(): iterable
    {
        yield 'int' => [10, 1000];
        yield 'float one' => [1.0, 100];
        yield 'float with cents' => [10.5, 1050];
        yield 'awkward float' => [19.99, 1999];
        yield 'string whole' => ['10', 1000];
        yield 'string decimals' => ['10.00', 1000];
        yield 'string one decimal' => ['10.5', 1050];
        yield 'string cents' => ['0.07', 7];
    }

    #[DataProvider('wire')]
    public function testFromWire(int|float|string $input, int $cents): void
    {
        self::assertSame($cents, Money::fromWire($input)->cents);
    }

    /** @return iterable<string, array{int|float|string}> */
    public static function badWire(): iterable
    {
        yield 'text' => ['ten'];
        yield 'three decimals' => ['1.234'];
        yield 'comma' => ['1,000'];
        yield 'negative string' => ['-5'];
        yield 'nan' => [NAN];
        yield 'huge' => [1.0E12];
    }

    #[DataProvider('badWire')]
    public function testFromWireRejects(int|float|string $input): void
    {
        $this->expectException(MalformedPayloadException::class);
        Money::fromWire($input);
    }

    public function testCentsAndEquality(): void
    {
        $m = Money::fromCents(1050);
        self::assertFalse($m->isWholeShillings());
        self::assertSame('KES 10.50', (string) $m);
        self::assertTrue($m->equals(Money::fromWire('10.50')));
        self::assertFalse($m->equals(Money::kes(10)));
    }
}
