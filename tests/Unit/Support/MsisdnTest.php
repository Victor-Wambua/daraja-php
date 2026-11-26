<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Exception\ValidationException;
use Daraja\Support\Msisdn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MsisdnTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function valid(): iterable
    {
        yield '07 local' => ['0700000001', '254700000001'];
        yield '01 local' => ['0110000001', '254110000001'];
        yield 'plus 254 07' => ['+254700000001', '254700000001'];
        yield 'plus 254 01' => ['+254110000001', '254110000001'];
        yield '254 07' => ['254700000001', '254700000001'];
        yield '254 01' => ['254110000001', '254110000001'];
        yield 'spaces' => ['0700 000 001', '254700000001'];
        yield 'dashes' => ['+254-700-000-001', '254700000001'];
        yield 'surrounding whitespace' => ["  0700000001\n", '254700000001'];
    }

    #[DataProvider('valid')]
    public function testNormalises(string $input, string $expected): void
    {
        self::assertSame($expected, Msisdn::parse($input)->value);
        self::assertSame($expected, (string) Msisdn::parse($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['07000000ab'];
        yield 'too short' => ['070000001'];
        yield 'too long' => ['07000000011'];
        yield 'landline 020' => ['0201234567'];
        yield 'bare nine digits' => ['700000001'];
        yield 'wrong country' => ['+255700000001'];
        yield 'plus without country' => ['+0700000001'];
        yield 'double zero prefix' => ['00254700000001'];
        yield '254 then 0' => ['2540700000001'];
        yield 'bad first digit' => ['254200000001'];
        yield 'injection' => ["0700000001'; DROP"];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $input): void
    {
        self::assertNull(Msisdn::tryParse($input));
        $this->expectException(ValidationException::class);
        Msisdn::parse($input);
    }

    public function testEquality(): void
    {
        self::assertTrue(Msisdn::parse('0700000001')->equals(Msisdn::parse('+254700000001')));
        self::assertFalse(Msisdn::parse('0700000001')->equals(Msisdn::parse('0700000002')));
    }
}
