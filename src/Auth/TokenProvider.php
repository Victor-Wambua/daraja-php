<?php

declare(strict_types=1);

namespace Daraja\Auth;

interface TokenProvider
{
    /** Return a token that is valid right now, fetching a new one only when needed. */
    public function token(): string;

    /** Forget the cached token, e.g. after Daraja answered 401. */
    public function invalidate(): void;
}
