<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Exception\ConfigurationException;
use Daraja\Safety\BeginResult;
use Daraja\Safety\IdempotencyStore;
use Daraja\Safety\PdoIdempotencyStore;

final class PdoIdempotencyStoreTest extends IdempotencyStoreContract
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }
    }

    protected function store(): IdempotencyStore
    {
        $store = new PdoIdempotencyStore(new \PDO('sqlite::memory:'), clock: $this->clock);
        $store->migrate();

        return $store;
    }

    private function fileStore(): PdoIdempotencyStore
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'idem') ?: throw new \RuntimeException('tempnam failed');
        $pdo = new \PDO('sqlite:' . $this->file);
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $store = new PdoIdempotencyStore($pdo, clock: $this->clock);
        $store->migrate();

        return $store;
    }

    public function testTwoConnectionsCannotBothAcquireTheSameKey(): void
    {
        $a = $this->fileStore();
        $b = $this->fileStore();
        self::assertSame(BeginResult::Acquired, $a->begin('stk:race'));
        self::assertSame(BeginResult::InProgress, $b->begin('stk:race'));
        $a->complete('stk:race');
        self::assertSame(BeginResult::AlreadyProcessed, $b->begin('stk:race'));
    }

    public function testStateSurvivesANewConnection(): void
    {
        $a = $this->fileStore();
        $a->begin('stk:persist');
        $a->complete('stk:persist');
        unset($a);
        self::assertSame(BeginResult::AlreadyProcessed, $this->fileStore()->begin('stk:persist'));
    }

    public function testPurgeRemovesOnlyOldCompletedKeys(): void
    {
        $s = $this->fileStore();
        $s->begin('old');
        $s->complete('old');
        $s->begin('stuck'); // never completed
        $this->clock->advance(86_400 * 30);
        $s->begin('new');
        $s->complete('new');

        self::assertSame(1, $s->purgeCompletedOlderThan(86_400));
        self::assertSame(BeginResult::Acquired, $s->begin('old'));
        self::assertSame(BeginResult::AlreadyProcessed, $s->begin('new'));
    }

    public function testRejectsUnsafeTableNames(): void
    {
        $this->expectException(ConfigurationException::class);
        new PdoIdempotencyStore(new \PDO('sqlite::memory:'), 'x; DROP TABLE y');
    }

    public function testRejectsEmptyOrOversizedKeys(): void
    {
        $s = $this->store();
        $this->expectException(\InvalidArgumentException::class);
        $s->begin(str_repeat('k', 192));
    }
}
