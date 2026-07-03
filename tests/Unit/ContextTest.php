<?php

declare(strict_types=1);

namespace Duat\Tests\Unit;

use Duat\Context;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Context::class)]
final class ContextTest extends TestCase
{
    public function testStartedAtDefaultsToClockNow(): void
    {
        $clock = new FakeClock(now: 123.5);

        $context = new Context(name: 'api', clock: $clock, randomizer: new FakeRandomizer());

        self::assertSame(123.5, $context->startedAt);
    }

    public function testExplicitStartedAtIsKept(): void
    {
        $context = new Context(
            name: 'api',
            clock: new FakeClock(now: 50.0),
            randomizer: new FakeRandomizer(),
            startedAt: 42.0,
        );

        self::assertSame(42.0, $context->startedAt);
    }

    public function testAttemptStartsAtOne(): void
    {
        $context = new Context(name: 'api', clock: new FakeClock(), randomizer: new FakeRandomizer());

        self::assertSame(1, $context->attempt);
    }

    public function testWithAttemptDerivesNewInstanceKeepingEverythingElse(): void
    {
        $clock = new FakeClock(now: 10.0);
        $randomizer = new FakeRandomizer();
        $context = new Context(
            name: 'api',
            clock: $clock,
            randomizer: $randomizer,
            metadata: ['tenant' => 42],
        );

        $clock->advance(5.0);
        $derived = $context->withAttempt(2);

        self::assertNotSame($context, $derived);
        self::assertSame(1, $context->attempt);
        self::assertSame(2, $derived->attempt);
        self::assertSame('api', $derived->name);
        self::assertSame($clock, $derived->clock);
        self::assertSame($randomizer, $derived->randomizer);
        self::assertSame(10.0, $derived->startedAt);
        self::assertSame(['tenant' => 42], $derived->metadata);
    }
}
