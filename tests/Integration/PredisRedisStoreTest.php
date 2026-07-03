<?php

declare(strict_types=1);

namespace Duat\Tests\Integration;

use Duat\Contract\StateStore;
use Duat\Store\RedisStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Predis\Client;

#[CoversClass(RedisStore::class)]
final class PredisRedisStoreTest extends RedisStoreTestCase
{
    protected function createStore(): StateStore
    {
        $client = new Client((string) self::redisUrl());
        $client->flushdb();

        return new RedisStore($client);
    }
}
