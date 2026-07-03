<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Policy;

use Duat\Backoff\Backoff;
use Duat\Context;
use Duat\Event\FallbackExecuted;
use Duat\Exception\RetryExhaustedException;
use Duat\Pipeline;
use Duat\Policy\FallbackPolicy;
use Duat\Policy\RetryPolicy;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use Duat\Tests\Support\SpyDispatcher;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(FallbackPolicy::class)]
#[CoversClass(FallbackExecuted::class)]
final class FallbackPolicyTest extends TestCase
{
    private function context(?SpyDispatcher $dispatcher = null): Context
    {
        return new Context(
            name: 'api',
            clock: new FakeClock(),
            randomizer: new FakeRandomizer(),
            events: $dispatcher,
        );
    }

    public function testReturnsInnerValueUntouchedOnSuccess(): void
    {
        $handlerCalled = false;
        $policy = new FallbackPolicy(function (Throwable $exception, Context $context) use (&$handlerCalled): string {
            $handlerCalled = true;

            return 'fallback';
        });

        $result = $policy->execute(static fn (Context $context): string => 'ok', $this->context());

        self::assertSame('ok', $result);
        self::assertFalse($handlerCalled);
    }

    public function testHandlerResultReplacesTheFailure(): void
    {
        $policy = new FallbackPolicy(static fn (Throwable $exception, Context $context): string => 'fallback');

        $result = $policy->execute(static function (Context $context): never {
            throw new RuntimeException('down');
        }, $this->context());

        self::assertSame('fallback', $result);
    }

    public function testHandlerReceivesTheExceptionAndContext(): void
    {
        $seen = [];
        $policy = new FallbackPolicy(function (Throwable $exception, Context $context) use (&$seen): ?string {
            $seen[] = [$exception->getMessage(), $context->name];

            return null;
        });

        $policy->execute(static function (Context $context): never {
            throw new RuntimeException('down');
        }, $this->context());

        self::assertSame([['down', 'api']], $seen);
    }

    public function testUnmatchedExceptionsPropagate(): void
    {
        $policy = new FallbackPolicy(
            static fn (Throwable $exception, Context $context): string => 'fallback',
            on: [RuntimeException::class],
        );

        $this->expectException(LogicException::class);

        $policy->execute(static function (Context $context): never {
            throw new LogicException('not handled');
        }, $this->context());
    }

    public function testEmitsFallbackExecutedBeforeTheHandlerRuns(): void
    {
        $dispatcher = new SpyDispatcher();
        $policy = new FallbackPolicy(static fn (Throwable $exception, Context $context): string => 'fallback');
        $thrown = new RuntimeException('down');

        $policy->execute(static function (Context $context) use ($thrown): never {
            throw $thrown;
        }, $this->context($dispatcher));

        $events = $dispatcher->events();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(FallbackExecuted::class, $event);
        self::assertSame('api', $event->name);
        self::assertSame($thrown, $event->exception);
    }

    public function testCatchesFailuresFromAnyInnerLayer(): void
    {
        $fallback = new FallbackPolicy(
            static fn (Throwable $exception, Context $context): string => $exception::class,
        );
        $retry = new RetryPolicy(maxAttempts: 2, backoff: Backoff::constant(10.0));

        $pipeline = new Pipeline([$fallback, $retry]);
        $result = $pipeline->execute(static function (): never {
            throw new RuntimeException('always down');
        }, $this->context());

        self::assertSame(RetryExhaustedException::class, $result);
    }
}
