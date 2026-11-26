<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Exception\MalformedPayloadException;
use Daraja\Support\Payload;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function testTypedAccessors(): void
    {
        $p = Payload::root(['a' => ['b' => '7', 'c' => 8, 'd' => 9.0, 'e' => 'x'], 'list' => [['k' => 1], ['k' => 2]]]);
        self::assertSame(7, $p->object('a')->int('b'));
        self::assertSame(8, $p->object('a')->int('c'));
        self::assertSame(9, $p->object('a')->int('d'));
        self::assertSame('x', $p->object('a')->string('e'));
        self::assertSame('8', $p->object('a')->string('c'));
        self::assertCount(2, $p->objects('list'));
        self::assertNull($p->optionalString('missing'));
        self::assertSame('fallback', $p->looseString('missing', 'fallback'));
    }

    public function testErrorsNameTheExactField(): void
    {
        $p = Payload::root(['Body' => ['stkCallback' => ['ResultCode' => 'abc']]]);
        try {
            $p->object('Body')->object('stkCallback')->int('ResultCode');
            self::fail('expected exception');
        } catch (MalformedPayloadException $e) {
            self::assertStringContainsString('Body.stkCallback.ResultCode', $e->getMessage());
        }
    }

    public function testRejectsWrongShapes(): void
    {
        $p = Payload::root(['list' => ['a' => 1], 'obj' => [1, 2], 'empty' => '', 'frac' => 1.5]);
        foreach ([
            static fn () => $p->objects('list'),
            static fn () => $p->object('obj'),
            static fn () => $p->string('empty'),
            static fn () => $p->int('frac'),
            static fn () => $p->string('missing'),
        ] as $call) {
            try {
                $call();
                self::fail('expected MalformedPayloadException');
            } catch (MalformedPayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
