<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Proxy;

use BadMethodCallException;
use Duat\Attributes\Bulkhead;
use Duat\Attributes\CircuitBreaker;
use Duat\Attributes\Fallback;
use Duat\Attributes\RateLimiter;
use Duat\Attributes\Retry;
use Duat\Attributes\Timeout;
use Duat\Event\DeadlineExceeded;
use Duat\Event\RetryAttempted;
use Duat\Exception\BulkheadFullException;
use Duat\Exception\CircuitOpenException;
use Duat\Exception\RateLimitExceededException;
use Duat\Exception\RetryExhaustedException;
use Duat\Proxy\Proxy;
use Duat\Proxy\ProxyFactory;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\Proxy\BareService;
use Duat\Tests\Support\Proxy\BrokenFallbackService;
use Duat\Tests\Support\Proxy\GuardedService;
use Duat\Tests\Support\Proxy\PaymentService;
use Duat\Tests\Support\Proxy\SlowService;
use Duat\Tests\Support\Proxy\StaticService;
use Duat\Tests\Support\SpyDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProxyFactory::class)]
#[CoversClass(Proxy::class)]
#[CoversClass(Retry::class)]
#[CoversClass(CircuitBreaker::class)]
#[CoversClass(Timeout::class)]
#[CoversClass(Bulkhead::class)]
#[CoversClass(RateLimiter::class)]
#[CoversClass(Fallback::class)]
final class ProxyFactoryTest extends TestCase
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

    private function factory(): ProxyFactory
    {
        return new ProxyFactory(
            store: $this->store,
            clock: $this->clock,
            randomizer: new FakeRandomizer(),
            events: $this->dispatcher,
        );
    }

    /**
     * Routes the call exactly like the engine would. Magic methods are
     * invisible to static analysis (the documented proxy trade-off), so
     * the suite calls __call directly instead of ignoring phpstan.
     */
    private function call(Proxy $proxy, string $method, mixed ...$arguments): mixed
    {
        return $proxy->__call($method, array_values($arguments));
    }

    public function testFactoryWorksWithDefaults(): void
    {
        $service = new PaymentService();
        $proxy = (new ProxyFactory())->wrap($service);

        self::assertSame('charged order-1 10', $this->call($proxy, 'charge', 'order-1', 10));
        self::assertSame(1, $service->calls);
    }

    public function testAnnotatedMethodIsRetried(): void
    {
        $service = new PaymentService(failFirst: 2);
        $proxy = $this->factory()->wrap($service);

        $result = $this->call($proxy, 'charge', 'order-1', 100);

        self::assertSame('charged order-1 100', $result);
        self::assertSame(3, $service->calls);
        self::assertSame([0.1, 0.1], $this->clock->sleeps());
    }

    public function testForwardsArgumentsAndReturnValues(): void
    {
        $service = new PaymentService();
        $proxy = $this->factory()->wrap($service);

        self::assertSame('charged order-9 42', $this->call($proxy, 'charge', 'order-9', 42));
        self::assertSame(1, $service->calls);
    }

    public function testPlainMethodsPassThroughUntouched(): void
    {
        $proxy = $this->factory()->wrap(new PaymentService());

        self::assertSame('plain untouched', $this->call($proxy, 'plain'));
        self::assertSame([], $this->dispatcher->events());
    }

    public function testUnknownMethodsThrowBadMethodCall(): void
    {
        $proxy = $this->factory()->wrap(new PaymentService());

        $this->expectException(BadMethodCallException::class);

        $this->call($proxy, 'nonexistent');
    }

    public function testFallbackMethodReceivesArgumentsAndException(): void
    {
        $service = new PaymentService();
        $proxy = $this->factory()->wrap($service);

        $result = $this->call($proxy, 'capture', 'order-7');

        self::assertSame(sprintf('pending order-7 (%s)', RetryExhaustedException::class), $result);
        self::assertSame(2, $service->calls);
    }

    public function testAttributeOrderDefinesTheNesting(): void
    {
        $service = new PaymentService();
        $proxy = $this->factory()->wrap($service);

        try {
            $this->call($proxy, 'fragile');
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        // Retry wraps the breaker: the first failure opens the circuit and
        // the second attempt is rejected without reaching the service.
        self::assertSame(1, $service->calls);
        self::assertSame([0.01], $this->clock->sleeps());
    }

    public function testEventsCarryTheCanonicalName(): void
    {
        $proxy = $this->factory()->wrap(new PaymentService(failFirst: 1));

        $this->call($proxy, 'charge', 'order-1', 10);

        $events = $this->dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(RetryAttempted::class, $events[0]);
        self::assertSame(PaymentService::class . '::charge', $events[0]->name);
    }

    public function testBreakerStateIsSharedBetweenProxiesFromTheSameFactory(): void
    {
        $factory = $this->factory();

        $first = new PaymentService();
        try {
            $this->call($factory->wrap($first), 'fragile');
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        $second = new PaymentService();

        try {
            $this->call($factory->wrap($second), 'fragile');
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        // The circuit was already open for Class::method, so the second
        // instance was never reached.
        self::assertSame(0, $second->calls);
    }

    public function testCallsAreCaseInsensitiveButStateIsCanonical(): void
    {
        $service = new PaymentService(failFirst: 1);
        $proxy = $this->factory()->wrap($service);

        self::assertSame('charged order-2 5', $this->call($proxy, 'ChArGe', 'order-2', 5));

        $events = $this->dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(RetryAttempted::class, $events[0]);
        self::assertSame(PaymentService::class . '::charge', $events[0]->name);
    }

    public function testTimeoutAttributeReportsLateSuccess(): void
    {
        $proxy = $this->factory()->wrap(new SlowService($this->clock));

        // camelCase on purpose: the proxy must normalize annotated method
        // names at wrap time, not only at call time.
        self::assertSame('late', $this->call($proxy, 'slowWork'));

        $events = $this->dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(DeadlineExceeded::class, $events[0]);
    }

    public function testBulkheadAttributeRejectsWhenFull(): void
    {
        $key = 'duat:bh:' . GuardedService::class . '::enter:active';
        $this->store->increment($key, ttlSeconds: 60);

        $proxy = $this->factory()->wrap(new GuardedService());

        $this->expectException(BulkheadFullException::class);

        $this->call($proxy, 'enter');
    }

    public function testRateLimiterAttributeRejectsOverTheLimit(): void
    {
        $proxy = $this->factory()->wrap(new GuardedService());

        self::assertSame('through', $this->call($proxy, 'throttled'));

        $this->expectException(RateLimitExceededException::class);

        $this->call($proxy, 'throttled');
    }

    public function testWrapRejectsClassesWithoutAttributes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No resilience attributes');

        $this->factory()->wrap(new BareService());
    }

    public function testWrapRejectsStaticAnnotatedMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('static methods');

        $this->factory()->wrap(new StaticService());
    }

    public function testWrapRejectsMissingFallbackMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowhere');

        $this->factory()->wrap(new BrokenFallbackService());
    }
}
