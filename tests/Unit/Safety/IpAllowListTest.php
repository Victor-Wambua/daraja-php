<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Exception\ConfigurationException;
use Daraja\Safety\IpAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAllowListTest extends TestCase
{
    public function testMatchesExactAddresses(): void
    {
        $list = new IpAllowList(['203.0.113.10', '203.0.113.11']);
        self::assertTrue($list->isAllowed('203.0.113.10'));
        self::assertTrue($list->isAllowed('203.0.113.11'));
        self::assertFalse($list->isAllowed('203.0.113.12'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cidr(): iterable
    {
        yield 'first in /24' => ['198.51.100.0', true];
        yield 'last in /24' => ['198.51.100.255', true];
        yield 'just outside' => ['198.51.101.0', false];
        yield 'other network' => ['10.0.0.1', false];
    }

    #[DataProvider('cidr')]
    public function testMatchesCidrRanges(string $ip, bool $expected): void
    {
        self::assertSame($expected, (new IpAllowList(['198.51.100.0/24']))->isAllowed($ip));
    }

    public function testNonByteAlignedPrefix(): void
    {
        $list = new IpAllowList(['192.0.2.128/25']);
        self::assertTrue($list->isAllowed('192.0.2.200'));
        self::assertFalse($list->isAllowed('192.0.2.127'));
    }

    public function testIpv6(): void
    {
        $list = new IpAllowList(['2001:db8::/32', '2001:db9::1']);
        self::assertTrue($list->isAllowed('2001:db8:abcd::1'));
        self::assertFalse($list->isAllowed('2001:dba::1'));
        self::assertTrue($list->isAllowed('2001:db9::1'));
        self::assertFalse($list->isAllowed('203.0.113.10'), 'an IPv4 address never matches an IPv6 range');
    }

    public function testMissingOrGarbageAddressesAreDenied(): void
    {
        $list = new IpAllowList(['0.0.0.0/0']);
        self::assertFalse($list->isAllowed(null));
        self::assertFalse($list->isAllowed('not-an-ip'));
        self::assertFalse($list->isAllowed(''));
        self::assertTrue($list->isAllowed('8.8.8.8'));
    }

    public function testAnEmptyListIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        new IpAllowList([]);
    }

    /** @return iterable<string, array{string}> */
    public static function badEntries(): iterable
    {
        yield 'garbage' => ['hello'];
        yield 'bad prefix' => ['10.0.0.0/33'];
        yield 'non numeric prefix' => ['10.0.0.0/x'];
        yield 'ipv6 bad prefix' => ['2001:db8::/129'];
    }

    #[DataProvider('badEntries')]
    public function testRejectsBadEntries(string $entry): void
    {
        $this->expectException(ConfigurationException::class);
        new IpAllowList([$entry]);
    }
}
