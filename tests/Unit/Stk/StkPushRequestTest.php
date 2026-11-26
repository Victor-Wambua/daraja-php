<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Stk;

use Daraja\Exception\ValidationException;
use Daraja\Stk\StkPushRequest;
use Daraja\Stk\TransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StkPushRequestTest extends TestCase
{
    public function testNormalisesPhoneAndDefaultsToPayBill(): void
    {
        $r = new StkPushRequest(100, '0700 000 001', 'https://shop.example/cb', 'ORD-1', 'Shop order');
        self::assertSame('254700000001', $r->phoneNumber->value);
        self::assertSame(TransactionType::PayBill, $r->type);
    }

    /** @return iterable<string, array{int}> */
    public static function badAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    #[DataProvider('badAmounts')]
    public function testRejectsAmountsBelowOne(int $amount): void
    {
        $this->expectException(ValidationException::class);
        new StkPushRequest($amount, '0700000001', 'https://shop.example/cb', 'ORD-1', 'Shop order');
    }

    public function testRejectsBadPhone(): void
    {
        $this->expectException(ValidationException::class);
        new StkPushRequest(100, '12345', 'https://shop.example/cb', 'ORD-1', 'Shop order');
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'no scheme' => ['shop.example/cb'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'ftp' => ['ftp://shop.example/cb'];
        yield 'empty' => [''];
    }

    #[DataProvider('badUrls')]
    public function testRejectsBadCallbackUrl(string $url): void
    {
        $this->expectException(ValidationException::class);
        new StkPushRequest(100, '0700000001', $url, 'ORD-1', 'Shop order');
    }

    public function testRequiresReferenceAndDescription(): void
    {
        $this->expectException(ValidationException::class);
        new StkPushRequest(100, '0700000001', 'https://shop.example/cb', '', 'Shop order');
    }

    public function testPartyBMustBeAShortCode(): void
    {
        $this->expectException(ValidationException::class);
        new StkPushRequest(100, '0700000001', 'https://shop.example/cb', 'A', 'B', TransactionType::BuyGoods, 'abc');
    }
}
