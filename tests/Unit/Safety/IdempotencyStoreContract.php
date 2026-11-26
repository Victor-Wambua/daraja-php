<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Safety\BeginResult;
use Daraja\Safety\IdempotencyStore;
use Daraja\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every IdempotencyStore must have. Subclasses only say how to build one.
 */
abstract class IdempotencyStoreContract extends TestCase
{
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    abstract protected function store(): IdempotencyStore;

    public function testFirstCallerAcquires(): void
    {
        self::assertSame(BeginResult::Acquired, $this->store()->begin('stk:1'));
    }

    public function testSecondCallerWhileFirstIsWorkingSeesInProgress(): void
    {
        $s = $this->store();
        $s->begin('stk:1');
        self::assertSame(BeginResult::InProgress, $s->begin('stk:1'));
    }

    public function testCompletedKeysAreAlreadyProcessed(): void
    {
        $s = $this->store();
        $s->begin('stk:1');
        $s->complete('stk:1');
        self::assertSame([BeginResult::AlreadyProcessed, BeginResult::AlreadyProcessed], [$s->begin('stk:1'), $s->begin('stk:1')]);
    }

    public function testReleaseLetsTheNextDeliveryTryAgain(): void
    {
        $s = $this->store();
        $s->begin('stk:1');
        $s->release('stk:1');
        self::assertSame(BeginResult::Acquired, $s->begin('stk:1'));
    }

    public function testReleaseNeverReopensACompletedKey(): void
    {
        $s = $this->store();
        $s->begin('stk:1');
        $s->complete('stk:1');
        $s->release('stk:1');
        self::assertSame(BeginResult::AlreadyProcessed, $s->begin('stk:1'));
    }

    public function testAnExpiredLockCanBeTakenOverAfterACrash(): void
    {
        $s = $this->store();
        $s->begin('stk:1', 60);
        $this->clock->advance(59);
        self::assertSame(BeginResult::InProgress, $s->begin('stk:1', 60));
        $this->clock->advance(1);
        self::assertSame(BeginResult::Acquired, $s->begin('stk:1', 60));
        // and now the new owner holds it
        self::assertSame(BeginResult::InProgress, $s->begin('stk:1', 60));
    }

    public function testKeysAreIndependent(): void
    {
        $s = $this->store();
        $s->begin('stk:1');
        $s->complete('stk:1');
        self::assertSame(BeginResult::Acquired, $s->begin('stk:2'));
    }
}
