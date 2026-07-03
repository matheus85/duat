<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Store;

use Duat\Contract\StateStore;
use Duat\Store\Psr16Store;
use Duat\Tests\Support\ArrayCache;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\StateStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Psr16Store::class)]
final class Psr16StoreTest extends StateStoreContractTestCase
{
    private FakeClock $clock;

    protected function createStore(): StateStore
    {
        $this->clock = new FakeClock();

        return new Psr16Store(new ArrayCache($this->clock), $this->clock);
    }

    protected function advanceTime(float $seconds): void
    {
        $this->clock->advance($seconds);
    }

    public function testKeysWithPsr16ReservedCharactersWork(): void
    {
        $this->store->set('duat:cb:{api}:state', 'open');

        self::assertSame('open', $this->store->get('duat:cb:{api}:state'));

        $this->store->delete('duat:cb:{api}:state');

        self::assertNull($this->store->get('duat:cb:{api}:state'));
    }
}
