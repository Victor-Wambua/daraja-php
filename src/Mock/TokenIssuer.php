<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** Stateless bearer tokens: base64url("<expiry>.<hmac>"). No database lookup needed to validate one. */
final readonly class TokenIssuer
{
    public function __construct(private string $signingKey)
    {
    }

    public function issue(int $expiresAt): string
    {
        $mac = hash_hmac('sha256', (string) $expiresAt, $this->signingKey);

        return rtrim(strtr(base64_encode($expiresAt . '.' . $mac), '+/', '-_'), '=');
    }

    public function isValid(string $token, int $now): bool
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $decoded, $m) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1], $this->signingKey), $m[2]) && (int) $m[1] > $now;
    }
}
