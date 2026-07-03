<?php

declare(strict_types=1);

namespace Duat\Store;

use Duat\Contract\Clock;
use Duat\Contract\StateStore;
use Duat\Support\SystemClock;
use Psr\SimpleCache\CacheInterface;

/**
 * Adapter over any PSR-16 cache.
 *
 * increment() and setIfNotExists() are read-modify-write and therefore NOT
 * atomic: under concurrency, updates can be lost and two workers can both
 * acquire the same lease. For the circuit breaker this means slightly off
 * window counts or an extra half-open probe, never corrupted state. Prefer
 * RedisStore in production.
 *
 * PSR-16 reserves {}()/\@: in keys, so keys are normalized by replacing
 * reserved characters with dots. PSR-16 also cannot report remaining TTLs,
 * so expiry metadata rides in a companion key; increments preserve the
 * original expiry with one second of precision.
 */
final class Psr16Store implements StateStore
{
    private const RESERVED_CHARACTERS = ['{', '}', '(', ')', '/', '\\', '@', ':'];
    private const EXPIRY_SUFFIX = '.expires-at';

    private readonly Clock $clock;

    public function __construct(
        private readonly CacheInterface $cache,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($this->normalize($key));

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $normalized = $this->normalize($key);

        if ($ttlSeconds === null) {
            $this->cache->set($normalized, $value);
            $this->cache->delete($normalized . self::EXPIRY_SUFFIX);

            return;
        }

        $this->cache->set($normalized, $value, $ttlSeconds);
        $this->cache->set(
            $normalized . self::EXPIRY_SUFFIX,
            (string) ($this->clock->now() + $ttlSeconds),
            $ttlSeconds,
        );
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $current = $this->get($key);
        $expiresAt = $this->expiresAt($key);
        $expired = $expiresAt !== null && $expiresAt <= $this->clock->now();

        if ($current === null || $expired) {
            $this->set($key, (string) $by, $ttlSeconds);

            return $by;
        }

        $value = (int) $current + $by;
        $normalized = $this->normalize($key);

        if ($expiresAt === null) {
            $this->cache->set($normalized, (string) $value);
        } else {
            $remaining = max(1, (int) ceil($expiresAt - $this->clock->now()));
            $this->cache->set($normalized, (string) $value, $remaining);
        }

        return $value;
    }

    public function setIfNotExists(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }

        $this->set($key, $value, $ttlSeconds);

        return true;
    }

    public function delete(string $key): void
    {
        $normalized = $this->normalize($key);
        $this->cache->delete($normalized);
        $this->cache->delete($normalized . self::EXPIRY_SUFFIX);
    }

    private function expiresAt(string $key): ?float
    {
        $raw = $this->cache->get($this->normalize($key) . self::EXPIRY_SUFFIX);

        return is_string($raw) ? (float) $raw : null;
    }

    private function normalize(string $key): string
    {
        return str_replace(self::RESERVED_CHARACTERS, '.', $key);
    }
}
