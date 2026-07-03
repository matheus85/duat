<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Support;

use Duat\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeClock::class)]
final class FakeClockTest extends TestCase
{
    public function testStartsAtGivenTime(): void
    {
        $clock = new FakeClock(now: 1_000.5);

        self::assertSame(1_000.5, $clock->now());
    }

    public function testStartsAtZeroByDefault(): void
    {
        self::assertSame(0.0, (new FakeClock())->now());
    }

    public function testSleepRecordsDurationAndAdvancesTime(): void
    {
        $clock = new FakeClock();

        $clock->sleep(0.25);
        $clock->sleep(1.25);

        self::assertSame([0.25, 1.25], $clock->sleeps());
        self::assertSame(1.5, $clock->now());
    }

    public function testAdvanceMovesTimeWithoutRecordingSleep(): void
    {
        $clock = new FakeClock();

        $clock->advance(30.0);

        self::assertSame(30.0, $clock->now());
        self::assertSame([], $clock->sleeps());
    }
}
