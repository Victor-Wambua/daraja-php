<?php

declare(strict_types=1);

namespace Daraja\Support;

/** A clock you move by hand. Used by the test-suite and handy in your own tests. */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string|\DateTimeImmutable $now = '2026-01-15 09:30:00 UTC')
    {
        $this->now = \is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('%+d seconds', $seconds));
    }
}
