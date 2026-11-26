<?php

declare(strict_types=1);

namespace Daraja\Support;

/** Source of "now". Swap it in tests to get deterministic timestamps. */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
