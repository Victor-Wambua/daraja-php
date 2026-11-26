<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Cache\ArrayCache;
use Daraja\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    public function testStoresAndExpires(): void
    {
        $clock = new FrozenClock();
        $cache = new ArrayCache($clock);
        $cache->set('k', 'v', 10);
        self::assertSame('v', $cache->get('k'));
        self::assertTrue($cache->has('k'));
        $clock->advance(10);
        self::assertNull($cache->get('k'));
        self::assertFalse($cache->has('k'));
    }

    public function testDeleteClearAndMultiple(): void
    {
        $cache = new ArrayCache();
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2], iterator_to_array($cache->getMultiple(['a', 'b'])));
        $cache->delete('a');
        self::assertSame('none', $cache->get('a', 'none'));
        $cache->deleteMultiple(['b']);
        self::assertFalse($cache->has('b'));
        $cache->set('c', 3);
        $cache->clear();
        self::assertFalse($cache->has('c'));
    }

    public function testDateIntervalTtlAndNonPositiveTtl(): void
    {
        $clock = new FrozenClock();
        $cache = new ArrayCache($clock);
        $cache->set('k', 'v', new \DateInterval('PT30S'));
        $clock->advance(29);
        self::assertSame('v', $cache->get('k'));
        $cache->set('gone', 'x', 0);
        self::assertFalse($cache->has('gone'));
    }
}
