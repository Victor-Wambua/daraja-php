<?php

declare(strict_types=1);

namespace Daraja\Auth;

/** An OAuth bearer token and the moment Daraja says it stops being valid. */
final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $value,
        public int $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['value' => '***', 'expiresAt' => $this->expiresAt];
    }
}
