<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use DomainException;
use Duat\Backoff\Backoff;
use Duat\Context;
use Duat\Event\RetryAttempted;
use Duat\Exception\CircuitOpenException;
use Duat\Exception\RetryExhaustedException;
use Duat\Policy\RetryPolicy;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use Exception;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryAttempted::class)]
#[CoversClass(RetryExhaustedException::class)]
#[CoversClass(CircuitOpenException::class)]
final class RetryPolicyTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    /**
     * @param list<float> $random
     */
    private function context(?SpyDispatcher $dispatcher = null, array $random = []): Context
    {
        return new Context(
            name: 'api',
            clock: $this->clock,
            randomizer: new FakeRandomizer(...$random),
            events: $dispatcher,
        );
    }

    /**
     * Callable that fails $failures times with RuntimeException before
     * returning 'ok'. Call counts land in $calls.
     */
    private function flaky(int $failures, int &$calls): callable
    {
        return function (Context $context) use ($failures, &$calls): string {
            $calls++;

            if ($calls <= $failures) {
                throw new RuntimeException('boom ' . $calls);
            }

            return 'ok';
        };
    }

    public function testFirstAttemptSuccessDoesNotWaitNorReExecute(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(maxAttempts: 3, backoff: Backoff::constant(100.0));

        $result = $policy->execute($this->flaky(0, $calls), $this->context());

        self::assertSame('ok', $result);
        self::assertSame(1, $calls);
        self::assertSame([], $this->clock->sleeps());
    }

    public function testRetriesUntilSuccessAndReturnsTheValue(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(maxAttempts: 3, backoff: Backoff::constant(100.0));

        $result = $policy->execute($this->flaky(2, $calls), $this->context());

        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
        self::assertSame([0.1, 0.1], $this->clock->sleeps());
    }

    public function testExhaustionThrowsWithLastExceptionAsPrevious(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(maxAttempts: 3, backoff: Backoff::constant(100.0));

        try {
            $policy->execute($this->flaky(10, $calls), $this->context());
            self::fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $e) {
            self::assertSame(3, $e->attempts);
            self::assertStringContainsString('3 attempts', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertSame('boom 3', $e->getPrevious()->getMessage());
        }

        self::assertSame(3, $calls);
    }

    public function testDelaysFollowTheBackoffExactly(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(
            maxAttempts: 4,
            backoff: Backoff::exponential(baseMs: 200.0, jitter: false),
        );

        try {
            $policy->execute($this->flaky(10, $calls), $this->context());
            self::fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException) {
        }

        self::assertSame([0.2, 0.4, 0.8], $this->clock->sleeps());
    }

    public function testJitteredDelaysDrawFromTheContextRandomizer(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::exponential(baseMs: 200.0),
        );

        try {
            $policy->execute($this->flaky(10, $calls), $this->context(random: [0.5, 0.25]));
            self::fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException) {
        }

        // attempt 1: 0.5 * 200ms; attempt 2: 0.25 * 400ms.
        self::assertSame([0.1, 0.1], $this->clock->sleeps());
    }

    public function testAbortOnRethrowsImmediately(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(
            maxAttempts: 5,
            backoff: Backoff::constant(100.0),
            abortOn: [DomainException::class],
        );

        try {
            $policy->execute(function (Context $context) use (&$calls): never {
                $calls++;

                throw new DomainException('invalid');
            }, $this->context());
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame('invalid', $e->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame([], $this->clock->sleeps());
    }

    public function testAbortOnTakesPrecedenceOverRetryOn(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            backoff: Backoff::constant(100.0),
            retryOn: [Exception::class],
            abortOn: [DomainException::class],
        );

        $this->expectException(DomainException::class);

        $policy->execute(static function (Context $context): never {
            throw new DomainException('both lists match, abort wins');
        }, $this->context());
    }

    public function testExceptionsOutsideRetryOnAreRethrown(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(
            maxAttempts: 5,
            backoff: Backoff::constant(100.0),
            retryOn: [RuntimeException::class],
        );

        try {
            $policy->execute(function (Context $context) use (&$calls): never {
                $calls++;

                throw new LogicException('not retryable');
            }, $this->context());
            self::fail('Expected LogicException.');
        } catch (LogicException) {
        }

        self::assertSame(1, $calls);
    }

    public function testCircuitOpenExceptionIsNeverRetried(): void
    {
        $calls = 0;
        $policy = new RetryPolicy(maxAttempts: 5, backoff: Backoff::constant(100.0));

        try {
            $policy->execute(function (Context $context) use (&$calls): never {
                $calls++;

                throw new CircuitOpenException('api');
            }, $this->context());
            self::fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        self::assertSame(1, $calls);
        self::assertSame([], $this->clock->sleeps());
    }

    public function testCircuitOpenExceptionIsNotRetriedEvenWhenExplicitlyRetryable(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            backoff: Backoff::constant(100.0),
            retryOn: [CircuitOpenException::class],
        );

        $this->expectException(CircuitOpenException::class);

        $policy->execute(static function (Context $context): never {
            throw new CircuitOpenException('api');
        }, $this->context());
    }

    public function testEmitsRetryAttemptedForEachRetriedFailure(): void
    {
        $calls = 0;
        $dispatcher = new SpyDispatcher();
        $policy = new RetryPolicy(maxAttempts: 3, backoff: Backoff::constant(100.0));

        $policy->execute($this->flaky(2, $calls), $this->context($dispatcher));

        $events = $dispatcher->events();
        self::assertCount(2, $events);
        self::assertContainsOnlyInstancesOf(RetryAttempted::class, $events);

        $first = $events[0];
        self::assertInstanceOf(RetryAttempted::class, $first);
        self::assertSame('api', $first->name);
        self::assertSame(1, $first->attempt);
        self::assertSame(100.0, $first->delayMs);
        self::assertSame('boom 1', $first->exception->getMessage());

        $second = $events[1];
        self::assertInstanceOf(RetryAttempted::class, $second);
        self::assertSame(2, $second->attempt);
    }

    public function testOnRetryHookReceivesExceptionAndContext(): void
    {
        $calls = 0;
        $seen = [];
        $policy = new RetryPolicy(
            maxAttempts: 3,
            backoff: Backoff::constant(100.0),
            onRetry: function (Throwable $exception, Context $context) use (&$seen): void {
                $seen[] = [$exception->getMessage(), $context->attempt];
            },
        );

        $policy->execute($this->flaky(2, $calls), $this->context());

        self::assertSame([['boom 1', 1], ['boom 2', 2]], $seen);
    }

    public function testInnerLayerSeesTheIncrementedAttempt(): void
    {
        $attempts = [];
        $policy = new RetryPolicy(maxAttempts: 3, backoff: Backoff::constant(100.0));

        $policy->execute(function (Context $context) use (&$attempts): string {
            $attempts[] = $context->attempt;

            if (count($attempts) < 3) {
                throw new RuntimeException('boom');
            }

            return 'ok';
        }, $this->context());

        self::assertSame([1, 2, 3], $attempts);
    }

    public function testRejectsMaxAttemptsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryPolicy(maxAttempts: 0, backoff: Backoff::constant(100.0));
    }
}
