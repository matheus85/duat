<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Support;

use Duat\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testNowTracksSystemTime(): void
    {
        $clock = new SystemClock();

        self::assertEqualsWithDelta(microtime(true), $clock->now(), 0.5);
    }

    public function testSleepWaitsAtLeastTheRequestedDuration(): void
    {
        $clock = new SystemClock();

        $before = microtime(true);
        $clock->sleep(0.001);
        $elapsed = microtime(true) - $before;

        self::assertGreaterThanOrEqual(0.0009, $elapsed);
    }

    public function testSleepIgnoresNonPositiveDurations(): void
    {
        $clock = new SystemClock();

        $before = microtime(true);
        $clock->sleep(-1.0);
        $clock->sleep(0.0);
        $elapsed = microtime(true) - $before;

        self::assertLessThan(0.05, $elapsed);
    }
}
