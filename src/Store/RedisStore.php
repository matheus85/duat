<?php

declare(strict_types=1);

namespace Duat\Store;

use Duat\Contract\StateStore;
use Predis\ClientInterface;
use Redis;
use UnexpectedValueException;

/**
 * Store backed by Redis through either ext-redis (\Redis) or a Predis
 * client, detected by type at the constructor.
 *
 * increment() runs a small Lua script so the counter bump and its
 * creation-only TTL apply atomically; setIfNotExists() maps to SET NX.
 * Safe under concurrent PHP-FPM workers.
 */
final class RedisStore implements StateStore
{
    private const INCREMENT_SCRIPT = <<<'LUA'
        local created = redis.call('EXISTS', KEYS[1]) == 0
        local value = redis.call('INCRBY', KEYS[1], ARGV[1])
        local ttl = tonumber(ARGV[2])
        if created and ttl > 0 then
            redis.call('EXPIRE', KEYS[1], ttl)
        end
        return value
        LUA;

    public function __construct(private readonly Redis|ClientInterface $client)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds === null) {
            $this->client->set($key, $value);

            return;
        }

        $this->client->setex($key, $ttlSeconds, $value);
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $arguments = [(string) $by, (string) ($ttlSeconds ?? 0)];

        $result = $this->client instanceof Redis
            ? $this->client->eval(self::INCREMENT_SCRIPT, [$key, ...$arguments], 1)
            : $this->client->eval(self::INCREMENT_SCRIPT, 1, $key, ...$arguments);

        if (!is_int($result)) {
            throw new UnexpectedValueException(
                sprintf('Expected an integer from the increment script, got %s.', get_debug_type($result)),
            );
        }

        return $result;
    }

    public function setIfNotExists(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds === null) {
            return (bool) $this->client->setnx($key, $value);
        }

        if ($this->client instanceof Redis) {
            return $this->client->set($key, $value, ['nx', 'ex' => $ttlSeconds]) === true;
        }

        return (string) $this->client->set($key, $value, 'EX', $ttlSeconds, 'NX') === 'OK';
    }

    public function delete(string $key): void
    {
        $this->client->del($key);
    }
}
