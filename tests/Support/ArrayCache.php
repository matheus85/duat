<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Duat\Contract\Clock;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal PSR-16 cache honoring TTLs against an injected clock, for
 * exercising Psr16Store deterministically.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: float|null}> */
    private array $items = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return $default;
        }

        if ($item['expiresAt'] !== null && $this->clock->now() >= $item['expiresAt']) {
            unset($this->items[$key]);

            return $default;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->ttlSeconds($ttl);

        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => $seconds === null ? null : $this->clock->now() + $seconds,
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
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private function ttlSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }

        return $ttl;
    }
}
