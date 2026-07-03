<?php

declare(strict_types=1);

namespace Duat\Contract;

interface StateStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, ?int $ttlSeconds = null): void;

    /**
     * Increments the counter stored at $key and returns the new value.
     *
     * A missing or expired key starts from zero. When $ttlSeconds is given,
     * it applies only to keys created by this call; the TTL of an existing
     * key is preserved. Behavior is undefined when the key holds a
     * non-numeric value.
     *
     * Must be atomic when the backend allows it. Implementations document
     * when it is not.
     */
    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int;

    public function delete(string $key): void;
}
