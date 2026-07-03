<?php

declare(strict_types=1);

namespace Duat\Tests\Integration;

use Duat\Tests\Support\StateStoreContractTestCase;

/**
 * Runs the full store contract against a real Redis, so time advances by
 * actually sleeping. TTLs are shortened to keep the suite bearable.
 * Requires REDIS_URL (e.g. redis://127.0.0.1:6379); skipped otherwise.
 */
abstract class RedisStoreTestCase extends StateStoreContractTestCase
{
    protected function setUp(): void
    {
        if (self::redisUrl() === null) {
            self::markTestSkipped('REDIS_URL is not set.');
        }

        parent::setUp();
    }

    protected function advanceTime(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    protected function ttlSeconds(): int
    {
        return 2;
    }

    protected function farFuture(): float
    {
        return 2.5;
    }

    protected function expiryGrace(): float
    {
        return 0.3;
    }

    protected static function redisUrl(): ?string
    {
        $url = getenv('REDIS_URL');

        return $url === false || $url === '' ? null : $url;
    }

    public function testSequentialIncrementsStayConsistent(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->store->increment('counter');
        }

        self::assertSame('500', $this->store->get('counter'));
    }
}
