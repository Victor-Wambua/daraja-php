<?php

declare(strict_types=1);

namespace Daraja\Cache;

use Daraja\Support\Clock;
use Daraja\Support\SystemClock;
use Psr\SimpleCache\CacheInterface;

/**
 * Process-local PSR-16 cache. Fine for tests and for CLI scripts; in PHP-FPM each request
 * starts empty, so give the client a shared cache (APCu, Redis, files) in production.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $items = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->expire($key);

        return \array_key_exists($key, $this->items) ? $this->items[$key]['value'] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            unset($this->items[$key]);

            return true;
        }
        $this->items[$key] = [
            'value' => $value,
            'expires' => $seconds === null ? null : $this->clock->now()->getTimestamp() + $seconds,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->expire($key);

        return \array_key_exists($key, $this->items);
    }

    private function expire(string $key): void
    {
        $expires = $this->items[$key]['expires'] ?? null;
        if ($expires !== null && $expires <= $this->clock->now()->getTimestamp()) {
            unset($this->items[$key]);
        }
    }

    private function seconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl instanceof \DateInterval) {
            $now = $this->clock->now();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }
}
