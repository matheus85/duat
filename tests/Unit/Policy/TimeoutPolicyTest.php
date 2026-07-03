<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use Duat\Context;
use Duat\Event\DeadlineExceeded;
use Duat\Exception\TimeoutExceededException;
use Duat\Policy\TimeoutPolicy;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TimeoutPolicy::class)]
#[CoversClass(TimeoutExceededException::class)]
#[CoversClass(DeadlineExceeded::class)]
final class TimeoutPolicyTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    private function context(?SpyDispatcher $dispatcher = null): Context
    {
        return new Context(
            name: 'api',
            clock: $this->clock,
            randomizer: new FakeRandomizer(),
            events: $dispatcher,
        );
    }

    public function testReturnsTheResultWhenWithinTheDeadline(): void
    {
        $dispatcher = new SpyDispatcher();
        $policy = new TimeoutPolicy(seconds: 5.0);

        $result = $policy->execute(function (Context $context): string {
            $this->clock->advance(4.0);

            return 'ok';
        }, $this->context($dispatcher));

        self::assertSame('ok', $result);
        self::assertSame([], $dispatcher->events());
    }

    public function testLateSuccessReturnsTheResultAndEmitsDeadlineExceeded(): void
    {
        $dispatcher = new SpyDispatcher();
        $policy = new TimeoutPolicy(seconds: 5.0);

        $result = $policy->execute(function (Context $context): string {
            $this->clock->advance(6.0);

            return 'late';
        }, $this->context($dispatcher));

        self::assertSame('late', $result);

        $events = $dispatcher->events();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(DeadlineExceeded::class, $event);
        self::assertSame('api', $event->name);
        self::assertSame(5.0, $event->timeoutSeconds);
        self::assertSame(6.0, $event->elapsedSeconds);
    }

    public function testLateSuccessThrowsWhenConfiguredTo(): void
    {
        $policy = new TimeoutPolicy(seconds: 5.0, throwOnLateSuccess: true);

        try {
            $policy->execute(function (Context $context): string {
                $this->clock->advance(6.0);

                return 'late';
            }, $this->context());
            self::fail('Expected TimeoutExceededException.');
        } catch (TimeoutExceededException $e) {
            self::assertSame('api', $e->name);
            self::assertSame(5.0, $e->timeoutSeconds);
            self::assertSame(6.0, $e->elapsedSeconds);
            self::assertStringContainsString('api', $e->getMessage());
        }
    }

    public function testInnerLayersSeeTheDeadlineAndBudget(): void
    {
        $policy = new TimeoutPolicy(seconds: 5.0);
        $seen = [];

        $policy->execute(function (Context $context) use (&$seen): ?string {
            $seen['deadline'] = $context->deadline;
            $this->clock->advance(2.0);
            $seen['budget'] = $context->remainingBudget();

            return null;
        }, $this->context());

        self::assertSame(5.0, $seen['deadline']);
        self::assertSame(3.0, $seen['budget']);
    }

    public function testTighterOuterDeadlineWins(): void
    {
        $policy = new TimeoutPolicy(seconds: 10.0);
        $seen = [];

        $policy->execute(function (Context $context) use (&$seen): ?string {
            $seen[] = $context->deadline;

            return null;
        }, $this->context()->withDeadline(5.0));

        self::assertSame([5.0], $seen);
    }

    public function testTighterOwnDeadlineWinsOverLooserOuter(): void
    {
        $policy = new TimeoutPolicy(seconds: 5.0);
        $seen = [];

        $policy->execute(function (Context $context) use (&$seen): ?string {
            $seen[] = $context->deadline;

            return null;
        }, $this->context()->withDeadline(10.0));

        self::assertSame([5.0], $seen);
    }

    public function testExceptionsPropagateUntouchedEvenWhenLate(): void
    {
        $policy = new TimeoutPolicy(seconds: 5.0, throwOnLateSuccess: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $policy->execute(function (Context $context): never {
            $this->clock->advance(6.0);

            throw new RuntimeException('boom');
        }, $this->context());
    }

    public function testRejectsNonPositiveSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeoutPolicy(seconds: 0.0);
    }
}
