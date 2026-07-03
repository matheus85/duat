<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use Duat\Contract\StateStore;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral contract every StateStore implementation must satisfy.
 * Concrete store test classes extend this and provide the store plus a way
 * to move time forward.
 */
abstract class StateStoreContractTestCase extends TestCase
{
    protected StateStore $store;

    abstract protected function createStore(): StateStore;

    abstract protected function advanceTime(float $seconds): void;

    protected function setUp(): void
    {
        $this->store = $this->createStore();
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        self::assertNull($this->store->get('missing'));
    }

    public function testSetThenGetReturnsValue(): void
    {
        $this->store->set('key', 'value');

        self::assertSame('value', $this->store->get('key'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->store->set('key', 'first');
        $this->store->set('key', 'second');

        self::assertSame('second', $this->store->get('key'));
    }

    public function testDeleteRemovesKey(): void
    {
        $this->store->set('key', 'value');
        $this->store->delete('key');

        self::assertNull($this->store->get('key'));
    }

    public function testDeleteMissingKeyDoesNothing(): void
    {
        $this->store->delete('missing');

        self::assertNull($this->store->get('missing'));
    }

    public function testValueSurvivesUntilTtl(): void
    {
        $this->store->set('key', 'value', ttlSeconds: 10);

        $this->advanceTime(9.75);

        self::assertSame('value', $this->store->get('key'));
    }

    public function testValueExpiresAfterTtl(): void
    {
        $this->store->set('key', 'value', ttlSeconds: 10);

        $this->advanceTime(10.0);

        self::assertNull($this->store->get('key'));
    }

    public function testValueWithoutTtlDoesNotExpire(): void
    {
        $this->store->set('key', 'value');

        $this->advanceTime(86_400.0);

        self::assertSame('value', $this->store->get('key'));
    }

    public function testSetWithoutTtlClearsPreviousTtl(): void
    {
        $this->store->set('key', 'first', ttlSeconds: 10);
        $this->store->set('key', 'second');

        $this->advanceTime(86_400.0);

        self::assertSame('second', $this->store->get('key'));
    }

    public function testIncrementStartsFromZeroForMissingKey(): void
    {
        self::assertSame(1, $this->store->increment('counter'));
    }

    public function testIncrementAddsToExistingValue(): void
    {
        $this->store->increment('counter');
        $this->store->increment('counter', by: 4);

        self::assertSame('5', $this->store->get('counter'));
    }

    public function testIncrementReturnsNewValue(): void
    {
        $this->store->increment('counter', by: 2);

        self::assertSame(5, $this->store->increment('counter', by: 3));
    }

    public function testIncrementWithNegativeByDecrements(): void
    {
        $this->store->increment('counter', by: 5);

        self::assertSame(3, $this->store->increment('counter', by: -2));
    }

    public function testIncrementAppliesTtlOnlyOnCreation(): void
    {
        $this->store->increment('counter', ttlSeconds: 10);
        $this->advanceTime(6.0);
        $this->store->increment('counter', ttlSeconds: 10);
        $this->advanceTime(6.0);

        // 12s after creation the original 10s TTL is gone, even though the
        // second increment asked for a fresh one.
        self::assertNull($this->store->get('counter'));
    }

    public function testIncrementAfterExpiryStartsFromZeroWithFreshTtl(): void
    {
        $this->store->increment('counter', by: 5, ttlSeconds: 10);
        $this->advanceTime(10.0);

        self::assertSame(1, $this->store->increment('counter', ttlSeconds: 10));

        $this->advanceTime(10.0);

        self::assertNull($this->store->get('counter'));
    }

    public function testSetIfNotExistsStoresWhenMissing(): void
    {
        self::assertTrue($this->store->setIfNotExists('lock', 'w1'));
        self::assertSame('w1', $this->store->get('lock'));
    }

    public function testSetIfNotExistsKeepsTheExistingValue(): void
    {
        $this->store->setIfNotExists('lock', 'w1');

        self::assertFalse($this->store->setIfNotExists('lock', 'w2'));
        self::assertSame('w1', $this->store->get('lock'));
    }

    public function testSetIfNotExistsStoresAgainAfterExpiry(): void
    {
        $this->store->setIfNotExists('lock', 'w1', ttlSeconds: 10);
        $this->advanceTime(10.0);

        self::assertTrue($this->store->setIfNotExists('lock', 'w2', ttlSeconds: 10));
        self::assertSame('w2', $this->store->get('lock'));
    }

    public function testSetIfNotExistsHonorsItsOwnTtl(): void
    {
        $this->store->setIfNotExists('lock', 'w1', ttlSeconds: 10);
        $this->advanceTime(10.0);

        self::assertNull($this->store->get('lock'));
    }
}
