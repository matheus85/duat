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

    /**
     * Base TTL used by the expiry tests. Stores tested against a real clock
     * override this with something small to keep the suite fast.
     */
    protected function ttlSeconds(): int
    {
        return 10;
    }

    /**
     * How far past any TTL the "never expires" checks travel.
     */
    protected function farFuture(): float
    {
        return 86_400.0;
    }

    /**
     * Extra margin added when a test crosses an expiry boundary. Fake
     * clocks assert the exact >= boundary with zero grace; stores measured
     * against a real clock cannot, so they add a little slack.
     */
    protected function expiryGrace(): float
    {
        return 0.0;
    }

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
        $this->store->set('key', 'value', ttlSeconds: $this->ttlSeconds());

        $this->advanceTime($this->ttlSeconds() * 0.75);

        self::assertSame('value', $this->store->get('key'));
    }

    public function testValueExpiresAfterTtl(): void
    {
        $this->store->set('key', 'value', ttlSeconds: $this->ttlSeconds());

        $this->advanceTime($this->ttlSeconds() + $this->expiryGrace());

        self::assertNull($this->store->get('key'));
    }

    public function testValueWithoutTtlDoesNotExpire(): void
    {
        $this->store->set('key', 'value');

        $this->advanceTime($this->farFuture());

        self::assertSame('value', $this->store->get('key'));
    }

    public function testSetWithoutTtlClearsPreviousTtl(): void
    {
        $this->store->set('key', 'first', ttlSeconds: $this->ttlSeconds());
        $this->store->set('key', 'second');

        $this->advanceTime($this->farFuture());

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
        $this->store->increment('counter', ttlSeconds: $this->ttlSeconds());
        $this->advanceTime($this->ttlSeconds() * 0.6);
        $this->store->increment('counter', ttlSeconds: $this->ttlSeconds());
        $this->advanceTime($this->ttlSeconds() * 0.6);

        // 1.2 TTLs after creation the original TTL is gone, even though the
        // second increment asked for a fresh one.
        self::assertNull($this->store->get('counter'));
    }

    public function testIncrementAfterExpiryStartsFromZeroWithFreshTtl(): void
    {
        $this->store->increment('counter', by: 5, ttlSeconds: $this->ttlSeconds());
        $this->advanceTime($this->ttlSeconds() + $this->expiryGrace());

        self::assertSame(1, $this->store->increment('counter', ttlSeconds: $this->ttlSeconds()));

        $this->advanceTime($this->ttlSeconds() + $this->expiryGrace());

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
        $this->store->setIfNotExists('lock', 'w1', ttlSeconds: $this->ttlSeconds());
        $this->advanceTime($this->ttlSeconds() + $this->expiryGrace());

        self::assertTrue($this->store->setIfNotExists('lock', 'w2', ttlSeconds: $this->ttlSeconds()));
        self::assertSame('w2', $this->store->get('lock'));
    }

    public function testSetIfNotExistsHonorsItsOwnTtl(): void
    {
        $this->store->setIfNotExists('lock', 'w1', ttlSeconds: $this->ttlSeconds());
        $this->advanceTime($this->ttlSeconds() + $this->expiryGrace());

        self::assertNull($this->store->get('lock'));
    }

    public function testKeysKeepIndependentTtls(): void
    {
        $this->store->increment('short', ttlSeconds: $this->ttlSeconds());
        $this->store->increment('long', ttlSeconds: $this->ttlSeconds() * 3);

        $this->advanceTime($this->ttlSeconds() * 0.6);
        $this->store->increment('short', ttlSeconds: $this->ttlSeconds());
        $this->store->increment('long', ttlSeconds: $this->ttlSeconds());

        $this->advanceTime($this->ttlSeconds() * 0.6 + $this->expiryGrace());

        self::assertNull($this->store->get('short'));
        self::assertSame('2', $this->store->get('long'));
    }

    public function testIncrementAfterTtlWasClearedKeepsTheValueAlive(): void
    {
        $this->store->set('key', '5', ttlSeconds: $this->ttlSeconds());
        $this->store->set('key', '5');

        self::assertSame(6, $this->store->increment('key'));

        $this->advanceTime($this->farFuture());

        self::assertSame('6', $this->store->get('key'));
    }
}
