<?php

declare(strict_types=1);

namespace Daraja\Support;

use Daraja\Exception\ValidationException;

/**
 * A Kenyan mobile number in the form Daraja expects: 254 followed by nine digits
 * starting 7 or 1 (e.g. 254700000001).
 *
 * Accepted input: 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX,
 * 2547XXXXXXXX, 2541XXXXXXXX. Spaces and dashes are ignored. Anything else is rejected.
 * The check is about shape only; it does not know which operator ranges are allocated.
 */
final readonly class Msisdn implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function parse(string $input): self
    {
        $parsed = self::tryParse($input);
        if ($parsed === null) {
            throw new ValidationException(
                'Phone number must be a Kenyan mobile number: 07XXXXXXXX, 01XXXXXXXX, +254XXXXXXXXX or 254XXXXXXXXX.',
            );
        }

        return $parsed;
    }

    public static function tryParse(string $input): ?self
    {
        $compact = preg_replace('/[ \-]/', '', trim($input));
        if ($compact === null || $compact === '') {
            return null;
        }
        if ($compact[0] === '+') {
            $compact = substr($compact, 1);
            if (!str_starts_with($compact, '254')) {
                return null;
            }
        }
        if (preg_match('/^(?:0|254)([71]\d{8})$/', $compact, $m) !== 1) {
            return null;
        }

        return new self('254' . $m[1]);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
