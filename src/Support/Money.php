<?php

declare(strict_types=1);

namespace Daraja\Support;

use Daraja\Exception\MalformedPayloadException;

/**
 * An amount of Kenyan shillings held as integer cents. Never a float.
 *
 * STK Push only accepts whole shillings, but callbacks and C2B payments can carry
 * cents, so the value object keeps the minor unit.
 */
final readonly class Money implements \Stringable
{
    private function __construct(public int $cents)
    {
    }

    public static function kes(int $shillings): self
    {
        return new self($shillings * 100);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Parse what Daraja sends: an int, a float such as 1.0, or a decimal string such as "10.50".
     *
     * @throws MalformedPayloadException
     */
    public static function fromWire(int|float|string $value): self
    {
        if (\is_int($value)) {
            return new self($value * 100);
        }
        if (\is_float($value)) {
            if (!is_finite($value) || abs($value) > 1.0E9) {
                throw new MalformedPayloadException('Amount is out of range.');
            }

            return new self((int) round($value * 100));
        }
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            throw new MalformedPayloadException('Amount is not a plain decimal number.');
        }
        $fraction = str_pad($m[2] ?? '', 2, '0');

        return new self((int) $m[1] * 100 + (int) $fraction);
    }

    public function isWholeShillings(): bool
    {
        return $this->cents % 100 === 0;
    }

    public function shillings(): int
    {
        return intdiv($this->cents, 100);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function __toString(): string
    {
        return \sprintf('KES %s.%02d', number_format(intdiv($this->cents, 100)), $this->cents % 100);
    }
}
