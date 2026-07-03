<?php

declare(strict_types=1);

namespace Duat\Store;

use Duat\Contract\Clock;
use Duat\Contract\StateStore;
use Duat\Support\SystemClock;

/**
 * Array-backed store local to the current process. State is not shared
 * across PHP-FPM workers, so it fits tests, CLI scripts and single-process
 * queue workers. Use a shared store in multi-worker deployments.
 */
final class InMemoryStore implements StateStore
{
    /** @var array<string, array{value: string, expiresAt: float|null}> */
    private array $items = [];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['expiresAt'] !== null && $this->clock->now() >= $item['expiresAt']) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $this->items[$key] = ['value' => $value, 'expiresAt' => $this->expiresAt($ttlSeconds)];
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $current = $this->get($key);
        $value = ($current === null ? 0 : (int) $current) + $by;

        // Mirrors Redis EXPIRE NX semantics: the TTL is set when the key is
        // created and never refreshed by later increments.
        $expiresAt = $current === null
            ? $this->expiresAt($ttlSeconds)
            : $this->items[$key]['expiresAt'];

        $this->items[$key] = ['value' => (string) $value, 'expiresAt' => $expiresAt];

        return $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    private function expiresAt(?int $ttlSeconds): ?float
    {
        return $ttlSeconds === null ? null : $this->clock->now() + $ttlSeconds;
    }
}
