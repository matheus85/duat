<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Store;

use Duat\Contract\StateStore;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\StateStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStore::class)]
final class InMemoryStoreTest extends StateStoreContractTestCase
{
    private FakeClock $clock;

    protected function createStore(): StateStore
    {
        $this->clock = new FakeClock();

        return new InMemoryStore($this->clock);
    }

    protected function advanceTime(float $seconds): void
    {
        $this->clock->advance($seconds);
    }
}
