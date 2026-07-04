<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use Duat\Context;
use Duat\Event\CallRejected;
use Duat\Event\RejectionReason;
use Duat\Exception\BulkheadFullException;
use Duat\Policy\BulkheadPolicy;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BulkheadPolicy::class)]
#[CoversClass(BulkheadFullException::class)]
final class BulkheadPolicyTest extends TestCase
{
    private const KEY = 'duat:bh:api:active';

    private FakeClock $clock;

    private InMemoryStore $store;

    private SpyDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(now: 1_000.0);
        $this->store = new InMemoryStore($this->clock);
        $this->dispatcher = new SpyDispatcher();
    }

    private function policy(int $maxConcurrent = 1, int $leaseSeconds = 60): BulkheadPolicy
    {
        return new BulkheadPolicy(
            store: $this->store,
            maxConcurrent: $maxConcurrent,
            leaseSeconds: $leaseSeconds,
        );
    }

    private function context(): Context
    {
        return new Context(
            name: 'api',
            clock: $this->clock,
            randomizer: new FakeRandomizer(),
            events: $this->dispatcher,
        );
    }

    public function testSequentialCallsAllPassAndReleaseTheirSlot(): void
    {
        $policy = $this->policy(maxConcurrent: 1);
        $context = $this->context();

        foreach ([1, 2, 3] as $round) {
            self::assertSame('ok', $policy->execute(static fn (Context $inner): string => 'ok', $context));
        }

        self::assertSame('0', $this->store->get(self::KEY));
    }

    public function testSlotIsReleasedWhenTheCallableFails(): void
    {
        $policy = $this->policy(maxConcurrent: 1);
        $context = $this->context();

        try {
            $policy->execute(static function (Context $inner): never {
                throw new RuntimeException('down');
            }, $context);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
        }

        self::assertSame('ok', $policy->execute(static fn (Context $inner): string => 'ok', $context));
    }

    public function testCountsCallsInFlight(): void
    {
        $policy = $this->policy(maxConcurrent: 2);
        $context = $this->context();
        $inner = null;

        $result = $policy->execute(function (Context $ctx) use ($policy, &$inner): string {
            $inner = $policy->execute(static fn (Context $deep): string => 'inner', $ctx);

            return 'outer';
        }, $context);

        self::assertSame('outer', $result);
        self::assertSame('inner', $inner);
    }

    public function testRejectsWhenAllSlotsAreHeld(): void
    {
        $policy = $this->policy(maxConcurrent: 1);
        $context = $this->context();
        $innerExecuted = false;

        try {
            $policy->execute(function (Context $ctx) use ($policy, &$innerExecuted): mixed {
                return $policy->execute(function (Context $deep) use (&$innerExecuted): string {
                    $innerExecuted = true;

                    return 'never';
                }, $ctx);
            }, $context);
            self::fail('Expected BulkheadFullException.');
        } catch (BulkheadFullException $e) {
            self::assertSame('api', $e->name);
            self::assertSame(1, $e->maxConcurrent);
        }

        self::assertFalse($innerExecuted);

        // The outer finally released its slot, so the next call is admitted.
        self::assertSame('ok', $policy->execute(static fn (Context $inner): string => 'ok', $context));
    }

    public function testRejectionRollsBackItsOwnAdmission(): void
    {
        // Two calls in flight on other workers.
        $this->store->increment(self::KEY, by: 2, ttlSeconds: 60);

        $policy = $this->policy(maxConcurrent: 2);

        try {
            $policy->execute(static fn (Context $inner): string => 'never', $this->context());
            self::fail('Expected BulkheadFullException.');
        } catch (BulkheadFullException) {
        }

        self::assertSame('2', $this->store->get(self::KEY));
    }

    public function testRejectionEmitsCallRejected(): void
    {
        $this->store->increment(self::KEY, ttlSeconds: 60);

        try {
            $this->policy(maxConcurrent: 1)->execute(static fn (Context $inner): string => 'never', $this->context());
            self::fail('Expected BulkheadFullException.');
        } catch (BulkheadFullException) {
        }

        $events = $this->dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(CallRejected::class, $events[0]);
        self::assertSame('api', $events[0]->name);
        self::assertSame(RejectionReason::BulkheadFull, $events[0]->reason);
    }

    public function testLeaseHealsSlotsLeakedByDeadProcesses(): void
    {
        // A worker died mid-call and never decremented.
        $this->store->increment(self::KEY, ttlSeconds: 60);

        $policy = $this->policy(maxConcurrent: 1, leaseSeconds: 60);
        $context = $this->context();

        try {
            $policy->execute(static fn (Context $inner): string => 'never', $context);
            self::fail('Expected BulkheadFullException.');
        } catch (BulkheadFullException) {
        }

        $this->clock->advance(60.0);

        self::assertSame('ok', $policy->execute(static fn (Context $inner): string => 'ok', $context));
    }

    public function testLeaseExpiryMidCallDoesNotLeaveANegativeCounter(): void
    {
        $policy = $this->policy(maxConcurrent: 1, leaseSeconds: 30);
        $context = $this->context();

        $policy->execute(function (Context $inner): string {
            $this->clock->advance(31.0);

            return 'slow';
        }, $context);

        self::assertNull($this->store->get(self::KEY));
        self::assertSame('ok', $policy->execute(static fn (Context $inner): string => 'ok', $context));
    }

    public function testRejectsInvalidMaxConcurrent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(maxConcurrent: 0);
    }

    public function testRejectsInvalidLease(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(leaseSeconds: 0);
    }
}
