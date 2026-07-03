<?php

declare(strict_types=1);

namespace Duat\Tests\Integration;

use Duat\Contract\StateStore;
use Duat\Store\RedisStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;

#[CoversClass(RedisStore::class)]
#[RequiresPhpExtension('redis')]
final class PhpredisRedisStoreTest extends RedisStoreTestCase
{
    protected function createStore(): StateStore
    {
        $parts = parse_url((string) self::redisUrl());

        $redis = new Redis();
        $redis->connect($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 6379);
        $redis->flushDB();

        return new RedisStore($redis);
    }
}
