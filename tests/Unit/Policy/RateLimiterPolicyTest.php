<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use Duat\Context;
use Duat\Event\CallRejected;
use Duat\Event\RejectionReason;
use Duat\Exception\RateLimitExceededException;
use Duat\Policy\RateLimiterPolicy;
use Duat\Store\InMemoryStore;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RateLimiterPolicy::class)]
#[CoversClass(RateLimitExceededException::class)]
final class RateLimiterPolicyTest extends TestCase
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

    private function policy(int $maxCalls = 3, int $perSeconds = 10): RateLimiterPolicy
    {
        return new RateLimiterPolicy(
            store: $this->store,
            maxCalls: $maxCalls,
            perSeconds: $perSeconds,
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

    private function succeed(RateLimiterPolicy $policy, Context $context): void
    {
        $result = $policy->execute(static fn (Context $inner): string => 'ok', $context);

        self::assertSame('ok', $result);
    }

    public function testAllowsCallsUpToTheLimit(): void
    {
        $policy = $this->policy(maxCalls: 3);
        $context = $this->context();

        foreach ([1, 2, 3] as $round) {
            $this->succeed($policy, $context);
        }

        self::assertSame([], $this->dispatcher->events());
    }

    public function testRejectsOverTheLimitWithoutExecuting(): void
    {
        $policy = $this->policy(maxCalls: 1, perSeconds: 10);
        $context = $this->context();
        $this->succeed($policy, $context);

        $called = false;

        try {
            $policy->execute(function (Context $inner) use (&$called): string {
                $called = true;

                return 'never';
            }, $context);
            self::fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            self::assertSame('api', $e->name);
            self::assertSame(1, $e->maxCalls);
            self::assertSame(10, $e->perSeconds);
            self::assertSame(10.0, $e->retryAfterSeconds);
        }

        self::assertFalse($called);

        $events = $this->dispatcher->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(CallRejected::class, $events[0]);
        self::assertSame(RejectionReason::RateLimited, $events[0]->reason);
    }

    public function testRetryAfterReflectsThePositionInsideTheWindow(): void
    {
        $policy = $this->policy(maxCalls: 1, perSeconds: 10);
        $context = $this->context();

        $this->clock->advance(3.5);
        $this->succeed($policy, $context);

        try {
            $policy->execute(static fn (Context $inner): string => 'never', $context);
            self::fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            self::assertSame(6.5, $e->retryAfterSeconds);
        }
    }

    public function testWindowResetsAfterPerSeconds(): void
    {
        $policy = $this->policy(maxCalls: 1, perSeconds: 10);
        $context = $this->context();
        $this->succeed($policy, $context);

        try {
            $policy->execute(static fn (Context $inner): string => 'never', $context);
            self::fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->clock->advance($e->retryAfterSeconds);
        }

        $this->succeed($policy, $context);
    }

    public function testFailedCallsStillConsumeTheBudget(): void
    {
        $policy = $this->policy(maxCalls: 1, perSeconds: 10);
        $context = $this->context();

        try {
            $policy->execute(static function (Context $inner): never {
                throw new RuntimeException('down');
            }, $context);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
        }

        $this->expectException(RateLimitExceededException::class);

        $policy->execute(static fn (Context $inner): string => 'never', $context);
    }

    public function testResourcesAreIsolatedByName(): void
    {
        $policy = $this->policy(maxCalls: 1, perSeconds: 10);
        $this->succeed($policy, $this->context('api'));

        $this->succeed($policy, $this->context('billing'));
    }

    public function testRejectsInvalidMaxCalls(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(maxCalls: 0);
    }

    public function testRejectsInvalidPerSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(perSeconds: 0);
    }
}
