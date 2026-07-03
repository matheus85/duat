<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Backoff;

use Duat\Backoff\ExponentialBackoff;
use Duat\Tests\Support\FakeRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExponentialBackoff::class)]
final class ExponentialBackoffTest extends TestCase
{
    public function testDelayDoublesOnEveryAttemptWithoutJitter(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0, jitter: false);
        $randomizer = new FakeRandomizer();

        self::assertSame(200.0, $backoff->delayMs(1, $randomizer));
        self::assertSame(400.0, $backoff->delayMs(2, $randomizer));
        self::assertSame(800.0, $backoff->delayMs(3, $randomizer));
    }

    public function testCapLimitsTheBase(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0, capMs: 500.0, jitter: false);
        $randomizer = new FakeRandomizer();

        self::assertSame(400.0, $backoff->delayMs(2, $randomizer));
        self::assertSame(500.0, $backoff->delayMs(3, $randomizer));
        self::assertSame(500.0, $backoff->delayMs(10, $randomizer));
    }

    public function testFullJitterScalesTheBaseByTheRandomValue(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0);

        self::assertSame(100.0, $backoff->delayMs(1, new FakeRandomizer(0.5)));
        self::assertSame(200.0, $backoff->delayMs(2, new FakeRandomizer(0.5)));
        self::assertSame(0.0, $backoff->delayMs(3, new FakeRandomizer(0.0)));
    }

    public function testJitterIsAppliedAfterTheCap(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0, capMs: 500.0);

        self::assertSame(250.0, $backoff->delayMs(5, new FakeRandomizer(0.5)));
    }

    public function testJitteredDelayStaysBelowTheBase(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0);

        self::assertLessThan(200.0, $backoff->delayMs(1, new FakeRandomizer(0.999)));
    }

    public function testJitterIsEnabledByDefault(): void
    {
        $backoff = new ExponentialBackoff(baseMs: 200.0);

        self::assertSame(50.0, $backoff->delayMs(1, new FakeRandomizer(0.25)));
    }

    public function testRejectsNegativeBase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoff(baseMs: -1.0);
    }

    public function testRejectsNegativeCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoff(baseMs: 200.0, capMs: -1.0);
    }
}
