<?php

declare(strict_types=1);

namespace Daraja\Support;

use Daraja\Exception\MalformedPayloadException;

/**
 * Strict, path-aware access to a decoded JSON object. Every accessor either returns a value
 * of the promised type or throws a MalformedPayloadException that names the exact field.
 */
final readonly class Payload
{
    /** @param array<mixed> $data */
    private function __construct(private array $data, private string $path)
    {
    }

    /** @param array<mixed> $data */
    public static function root(array $data): self
    {
        return new self($data, '');
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->fail($key, 'an object');
        }

        return new self($value, $this->child($key));
    }

    public function optionalObject(string $key): ?self
    {
        return $this->has($key) ? $this->object($key) : null;
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->fail($key, 'a list');
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
                throw $this->fail($key . '[' . $i . ']', 'an object');
            }
            $out[] = new self($item, $this->child($key . '[' . $i . ']'));
        }

        return $out;
    }

    /** A non-empty string. Numbers are accepted and stringified, since Daraja is loose about them. */
    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }
        if (!\is_string($value) || $value === '') {
            throw $this->fail($key, 'a non-empty string');
        }

        return $value;
    }

    /** A string that may be empty (Daraja sends "" for unused fields). */
    public function looseString(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (!\is_string($value)) {
            throw $this->fail($key, 'a string');
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    /** An integer, or an integer written as a string ("0", "1032"). */
    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (\is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        if (\is_float($value) && floor($value) === $value && abs($value) < 1.0E15) {
            return (int) $value;
        }
        if (!\is_int($value)) {
            throw $this->fail($key, 'an integer');
        }

        return $value;
    }

    public function optionalInt(string $key): ?int
    {
        return $this->has($key) ? $this->int($key) : null;
    }

    /** @return int|float|string|bool|null */
    public function scalar(string $key): int|float|string|bool|null
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !\is_scalar($value)) {
            throw $this->fail($key, 'a scalar value');
        }

        return $value;
    }

    private function child(string $key): string
    {
        return $this->path === '' ? $key : $this->path . '.' . $key;
    }

    private function fail(string $key, string $expected): MalformedPayloadException
    {
        return new MalformedPayloadException(\sprintf('Field "%s" must be %s.', $this->child($key), $expected));
    }
}
