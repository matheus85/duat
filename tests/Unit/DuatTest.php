<?php

declare(strict_types=1);

namespace Duat\Tests\Unit;

use Duat\Backoff\Backoff;
use Duat\Context;
use Duat\Duat;
use Duat\Event\DeadlineExceeded;
use Duat\Event\RetryAttempted;
use Duat\Exception\BulkheadFullException;
use Duat\Exception\CircuitOpenException;
use Duat\Exception\RateLimitExceededException;
use Duat\Exception\RetryExhaustedException;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(Duat::class)]
final class DuatTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(now: 1_000.0);
        Duat::flushDefaultStore();
    }

    protected function tearDown(): void
    {
        Duat::flushDefaultStore();
    }

    private function chain(string $name = 'api', float ...$random): Duat
    {
        return Duat::for($name)
            ->clock($this->clock)
            ->randomizer(new FakeRandomizer(...$random));
    }

    /**
     * Callable failing $failures times with RuntimeException before
     * returning 'ok'.
     */
    private function flaky(int $failures, int &$calls): callable
    {
        return function () use ($failures, &$calls): string {
            $calls++;

            if ($calls <= $failures) {
                throw new RuntimeException('boom ' . $calls);
            }

            return 'ok';
        };
    }

    public function testCallWithoutPoliciesExecutesTheCallable(): void
    {
        self::assertSame('ok', Duat::for('api')->call(static fn (): string => 'ok'));
    }

    public function testBuilderIsImmutable(): void
    {
        $base = $this->chain();
        $withRetry = $base->retry(maxAttempts: 3, backoff: Backoff::constant(10.0));

        self::assertNotSame($base, $withRetry);

        $calls = 0;

        try {
            $base->call($this->flaky(10, $calls));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
        }

        self::assertSame(1, $calls);

        $calls = 0;
        self::assertSame('ok', $withRetry->call($this->flaky(2, $calls)));
        self::assertSame(3, $calls);
    }

    public function testRetryWaitsAccordingToTheBackoff(): void
    {
        $calls = 0;
        $result = $this->chain()
            ->retry(maxAttempts: 3, backoff: Backoff::constant(100.0))
            ->call($this->flaky(2, $calls));

        self::assertSame('ok', $result);
        self::assertSame([0.1, 0.1], $this->clock->sleeps());
    }

    public function testDefaultBackoffIsExponentialWithFullJitter(): void
    {
        $calls = 0;
        $this->chain('api', 0.5)
            ->retry(maxAttempts: 2)
            ->call($this->flaky(1, $calls));

        // 0.5 * 200ms base.
        self::assertSame([0.1], $this->clock->sleeps());
    }

    public function testFallbackIsAlwaysTheOutermostLayer(): void
    {
        $result = $this->chain()
            ->fallback(static fn (Throwable $exception): string => $exception::class)
            ->retry(maxAttempts: 2, backoff: Backoff::constant(10.0))
            ->call(static fn (): never => throw new RuntimeException('always down'));

        self::assertSame(RetryExhaustedException::class, $result);
    }

    public function testCallOrderDefinesTheNesting(): void
    {
        $calls = 0;
        $chain = $this->chain()
            ->retry(maxAttempts: 3, backoff: Backoff::constant(10.0))
            ->circuitBreaker(failureRateThreshold: 1.0, minimumCalls: 1)
            ->store(new InMemoryStore($this->clock));

        try {
            $chain->call($this->flaky(10, $calls));
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        // Retry wraps the breaker: the first failure opens the circuit, the
        // second attempt is rejected before reaching the callable and the
        // rejection is not retried.
        self::assertSame(1, $calls);
        self::assertSame([0.01], $this->clock->sleeps());
    }

    public function testCircuitBreakerSharesStateThroughTheProvidedStore(): void
    {
        $store = new InMemoryStore($this->clock);
        $breaker = fn (): Duat => $this->chain()
            ->circuitBreaker(failureRateThreshold: 1.0, minimumCalls: 2)
            ->store($store);

        $calls = 0;

        foreach ([1, 2] as $round) {
            try {
                $breaker()->call($this->flaky(10, $calls));
                self::fail('Expected RuntimeException.');
            } catch (RuntimeException) {
            }
        }

        $this->expectException(CircuitOpenException::class);

        $breaker()->call(static fn (): string => 'ok');
    }

    public function testDefaultStoreIsSharedAcrossBuildersInTheProcess(): void
    {
        $breaker = fn (): Duat => $this->chain()
            ->circuitBreaker(failureRateThreshold: 1.0, minimumCalls: 2);

        $calls = 0;

        foreach ([1, 2] as $round) {
            try {
                $breaker()->call($this->flaky(10, $calls));
                self::fail('Expected RuntimeException.');
            } catch (RuntimeException) {
            }
        }

        $this->expectException(CircuitOpenException::class);

        $breaker()->call(static fn (): string => 'ok');
    }

    public function testEventsReachTheDispatcher(): void
    {
        $dispatcher = new SpyDispatcher();
        $calls = 0;

        $this->chain('sefaz')
            ->events($dispatcher)
            ->retry(maxAttempts: 2, backoff: Backoff::constant(10.0))
            ->call($this->flaky(1, $calls));

        $events = $dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(RetryAttempted::class, $events[0]);
        self::assertSame('sefaz', $events[0]->name);
    }

    public function testTimeoutEmitsDeadlineExceededOnLateSuccess(): void
    {
        $dispatcher = new SpyDispatcher();

        $result = $this->chain()
            ->events($dispatcher)
            ->timeout(seconds: 5.0)
            ->call(function (): string {
                $this->clock->advance(6.0);

                return 'late';
            });

        self::assertSame('late', $result);
        self::assertCount(1, $dispatcher->events());
        self::assertInstanceOf(DeadlineExceeded::class, $dispatcher->events()[0]);
    }

    public function testBulkheadRejectsWhenFull(): void
    {
        $store = new InMemoryStore($this->clock);
        $store->increment('duat:bh:api:active', ttlSeconds: 60);

        $this->expectException(BulkheadFullException::class);

        $this->chain()
            ->bulkhead(maxConcurrent: 1)
            ->store($store)
            ->call(static fn (): string => 'never');
    }

    public function testRateLimiterRejectsOverTheLimit(): void
    {
        $chain = $this->chain()
            ->rateLimiter(maxCalls: 1, perSeconds: 10)
            ->store(new InMemoryStore($this->clock));

        self::assertSame('ok', $chain->call(static fn (): string => 'ok'));

        $this->expectException(RateLimitExceededException::class);

        $chain->call(static fn (): string => 'never');
    }

    public function testFallbackHandlerReceivesTheExceptionAndContext(): void
    {
        $seen = [];

        $this->chain('sefaz')
            ->fallback(function (Throwable $exception, Context $context) use (&$seen): ?string {
                $seen[] = [$exception->getMessage(), $context->name];

                return null;
            })
            ->call(static fn (): never => throw new RuntimeException('down'));

        self::assertSame([['down', 'sefaz']], $seen);
    }
}
