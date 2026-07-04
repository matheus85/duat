<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use Closure;
use DomainException;
use Duat\Context;
use Duat\Contract\StateStore;
use Duat\Event\CallRejected;
use Duat\Event\CircuitClosed;
use Duat\Event\CircuitHalfOpened;
use Duat\Event\CircuitOpened;
use Duat\Event\RejectionReason;
use Duat\Exception\CircuitOpenException;
use Duat\Policy\CircuitBreakerPolicy;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(CircuitBreakerPolicy::class)]
#[CoversClass(CircuitOpened::class)]
#[CoversClass(CircuitHalfOpened::class)]
#[CoversClass(CircuitClosed::class)]
#[CoversClass(CallRejected::class)]
final class CircuitBreakerPolicyTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryStore $store;

    private SpyDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(now: 1_000.0);
        $this->store = new InMemoryStore($this->clock);
        $this->dispatcher = new SpyDispatcher();
    }

    /**
     * @param list<class-string<Throwable>> $recordOn
     */
    private function policy(
        int $windowSeconds = 10,
        int $halfOpenMaxCalls = 1,
        array $recordOn = [Throwable::class],
    ): CircuitBreakerPolicy {
        return new CircuitBreakerPolicy(
            store: $this->store,
            failureRateThreshold: 0.5,
            minimumCalls: 4,
            windowSeconds: $windowSeconds,
            cooldownSeconds: 30,
            halfOpenMaxCalls: $halfOpenMaxCalls,
            recordOn: $recordOn,
        );
    }

    private function context(string $name = 'api'): Context
    {
        return new Context(
            name: $name,
            clock: $this->clock,
            randomizer: new FakeRandomizer(),
            events: $this->dispatcher,
        );
    }

    private function succeed(CircuitBreakerPolicy $policy, Context $context): void
    {
        $result = $policy->execute(static fn (Context $inner): string => 'ok', $context);

        self::assertSame('ok', $result);
    }

    private function failWith(CircuitBreakerPolicy $policy, Context $context, Throwable $exception): void
    {
        try {
            $policy->execute(static fn (Context $inner): never => throw $exception, $context);
            self::fail('Expected the induced failure to propagate.');
        } catch (Throwable $caught) {
            self::assertSame($exception, $caught);
        }
    }

    private function assertRejected(CircuitBreakerPolicy $policy, Context $context): void
    {
        $called = false;

        try {
            $policy->execute(function (Context $inner) use (&$called): string {
                $called = true;

                return 'ok';
            }, $context);
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        self::assertFalse($called);
    }

    private function openCircuit(CircuitBreakerPolicy $policy, Context $context): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->failWith($policy, $context, new DomainException('down'));
        }
    }

    /**
     * @param class-string $class
     * @return list<object>
     */
    private function eventsOf(string $class): array
    {
        return array_values(array_filter(
            $this->dispatcher->events(),
            static fn (object $event): bool => $event instanceof $class,
        ));
    }

    public function testOpensWhenThresholdIsReachedWithMinimumCalls(): void
    {
        $policy = $this->policy();
        $context = $this->context();

        $this->succeed($policy, $context);
        $this->succeed($policy, $context);
        $this->failWith($policy, $context, new DomainException('down'));

        self::assertSame([], $this->eventsOf(CircuitOpened::class));

        $this->failWith($policy, $context, new DomainException('down'));

        $opened = $this->eventsOf(CircuitOpened::class);
        self::assertCount(1, $opened);
        self::assertInstanceOf(CircuitOpened::class, $opened[0]);
        self::assertSame('api', $opened[0]->name);
        self::assertSame(0.5, $opened[0]->failureRate);
    }

    public function testDoesNotOpenBeforeMinimumCalls(): void
    {
        $policy = $this->policy();
        $context = $this->context();

        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        $this->succeed($policy, $context);

        self::assertSame([], $this->eventsOf(CircuitOpened::class));
    }

    public function testOpenRejectsWithoutExecutingTheCallable(): void
    {
        $policy = $this->policy();
        $context = $this->context();
        $this->openCircuit($policy, $context);

        $this->assertRejected($policy, $context);

        $rejected = $this->eventsOf(CallRejected::class);
        self::assertCount(1, $rejected);
        self::assertInstanceOf(CallRejected::class, $rejected[0]);
        self::assertSame('api', $rejected[0]->name);
        self::assertSame(RejectionReason::CircuitOpen, $rejected[0]->reason);
    }

    public function testWindowExpiresOldRecordsExactlyAtTheEdge(): void
    {
        $policy = $this->policy();
        $context = $this->context();

        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        // Ten seconds later the window covers seconds 1001..1010: the two
        // failures recorded at second 1000 just fell out.
        $this->clock->advance(10.0);

        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        $this->succeed($policy, $context);
        self::assertSame([], $this->eventsOf(CircuitOpened::class));

        // One more failure makes it 3 failures / 4 calls in-window: opens.
        $this->failWith($policy, $context, new DomainException('down'));
        self::assertCount(1, $this->eventsOf(CircuitOpened::class));
        $this->assertRejected($policy, $context);
    }

    public function testOldestBucketInsideTheWindowStillCounts(): void
    {
        $policy = $this->policy();
        $context = $this->context();

        // Fractional times force the bucket math through floor().
        $this->clock->advance(0.25);

        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        // Nine seconds later those failures sit in the oldest bucket the
        // window still covers (1000, with the window at 1000..1009).
        $this->clock->advance(9.0);

        $this->failWith($policy, $context, new DomainException('down'));

        self::assertCount(1, $this->eventsOf(CircuitOpened::class));
        $this->assertRejected($policy, $context);
    }

    public function testOpenTransitionsToHalfOpenAfterCooldownAndSuccessCloses(): void
    {
        $policy = $this->policy();
        $context = $this->context();
        $this->openCircuit($policy, $context);

        $this->assertRejected($policy, $context);

        $this->clock->advance(30.0);

        $this->succeed($policy, $context);

        self::assertCount(1, $this->eventsOf(CircuitHalfOpened::class));
        self::assertCount(1, $this->eventsOf(CircuitClosed::class));

        $this->succeed($policy, $context);
    }

    public function testCircuitClosedResetsTheWindow(): void
    {
        $policy = $this->policy(windowSeconds: 100);
        $context = $this->context();

        $this->succeed($policy, $context);
        $this->succeed($policy, $context);
        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        self::assertCount(1, $this->eventsOf(CircuitOpened::class));

        $this->clock->advance(30.0);
        $this->succeed($policy, $context);

        self::assertCount(1, $this->eventsOf(CircuitClosed::class));

        // Without the reset these three failures would evaluate against the
        // still-in-window pre-open records and reopen the circuit.
        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));
        $this->failWith($policy, $context, new DomainException('down'));

        $this->succeed($policy, $context);
        self::assertCount(1, $this->eventsOf(CircuitOpened::class));
    }

    public function testHalfOpenFailureReopensWithFreshCooldown(): void
    {
        $policy = $this->policy();
        $context = $this->context();
        $this->openCircuit($policy, $context);

        $this->clock->advance(30.0);

        $this->failWith($policy, $context, new DomainException('still down'));

        $opened = $this->eventsOf(CircuitOpened::class);
        self::assertCount(2, $opened);
        self::assertInstanceOf(CircuitOpened::class, $opened[1]);
        self::assertNull($opened[1]->failureRate);

        $this->assertRejected($policy, $context);

        $this->clock->advance(29.0);
        $this->assertRejected($policy, $context);

        $this->clock->advance(1.5);
        $this->succeed($policy, $context);
        self::assertCount(1, $this->eventsOf(CircuitClosed::class));
    }

    public function testHalfOpenAdmitsOnlyTheConfiguredProbes(): void
    {
        $policy = $this->policy();
        $context = $this->context();

        // Simulates a half-open circuit whose single probe slot is already
        // taken by an in-flight call from another worker.
        $this->store->set('duat:cb:api:state', 'half_open');
        $this->store->increment('duat:cb:api:half_open_calls');

        $this->assertRejected($policy, $context);
        self::assertCount(1, $this->eventsOf(CallRejected::class));
    }

    public function testHalfOpenNeedsAllProbesToSucceedBeforeClosing(): void
    {
        $policy = $this->policy(halfOpenMaxCalls: 2);
        $context = $this->context();
        $this->openCircuit($policy, $context);

        $this->clock->advance(30.0);

        $this->succeed($policy, $context);
        self::assertCount(0, $this->eventsOf(CircuitClosed::class));

        $this->succeed($policy, $context);
        self::assertCount(1, $this->eventsOf(CircuitClosed::class));
    }

    public function testNeutralProbeFailureReleasesTheSlot(): void
    {
        $policy = $this->policy(recordOn: [RuntimeException::class]);
        $context = $this->context();

        for ($i = 0; $i < 4; $i++) {
            $this->failWith($policy, $context, new RuntimeException('down'));
        }

        $this->clock->advance(30.0);

        // DomainException is not recordable: it proves nothing about the
        // dependency, so it must not reopen nor consume the probe slot.
        $this->failWith($policy, $context, new DomainException('unrelated'));

        self::assertCount(1, $this->eventsOf(CircuitOpened::class));

        $this->succeed($policy, $context);
        self::assertCount(1, $this->eventsOf(CircuitClosed::class));
    }

    public function testRecordOnIgnoresOtherExceptions(): void
    {
        $policy = $this->policy(recordOn: [RuntimeException::class]);
        $context = $this->context();

        for ($i = 0; $i < 4; $i++) {
            $this->failWith($policy, $context, new DomainException('not recorded'));
        }

        $this->succeed($policy, $context);
        self::assertSame([], $this->eventsOf(CircuitOpened::class));
    }

    public function testResourcesAreIsolatedByName(): void
    {
        $policy = $this->policy();
        $this->openCircuit($policy, $this->context('api'));

        $this->assertRejected($policy, $this->context('api'));
        $this->succeed($policy, $this->context('billing'));
    }

    /**
     * @return iterable<string, array{Closure(StateStore): CircuitBreakerPolicy}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'threshold at zero' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, failureRateThreshold: 0.0)];
        yield 'threshold above one' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, failureRateThreshold: 1.5)];
        yield 'zero minimum calls' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, minimumCalls: 0)];
        yield 'zero window' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, windowSeconds: 0)];
        yield 'zero cooldown' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, cooldownSeconds: 0)];
        yield 'zero half-open calls' => [static fn (StateStore $store): CircuitBreakerPolicy => new CircuitBreakerPolicy($store, halfOpenMaxCalls: 0)];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(Closure $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory(new InMemoryStore(new FakeClock()));
    }
}
