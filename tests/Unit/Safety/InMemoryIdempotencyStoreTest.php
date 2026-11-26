<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Safety;

use Daraja\Safety\IdempotencyStore;
use Daraja\Safety\InMemoryIdempotencyStore;

final class InMemoryIdempotencyStoreTest extends IdempotencyStoreContract
{
    protected function store(): IdempotencyStore
    {
        return new InMemoryIdempotencyStore($this->clock);
    }
}
