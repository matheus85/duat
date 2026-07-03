<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Backoff;

use Duat\Backoff\LinearBackoff;
use Duat\Tests\Support\FakeRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinearBackoff::class)]
final class LinearBackoffTest extends TestCase
{
    public function testDelayGrowsLinearlyWithAttempts(): void
    {
        $backoff = new LinearBackoff(baseMs: 100.0);
        $randomizer = new FakeRandomizer();

        self::assertSame(100.0, $backoff->delayMs(1, $randomizer));
        self::assertSame(200.0, $backoff->delayMs(2, $randomizer));
        self::assertSame(300.0, $backoff->delayMs(3, $randomizer));
    }

    public function testCapLimitsTheDelay(): void
    {
        $backoff = new LinearBackoff(baseMs: 100.0, capMs: 250.0);
        $randomizer = new FakeRandomizer();

        self::assertSame(200.0, $backoff->delayMs(2, $randomizer));
        self::assertSame(250.0, $backoff->delayMs(3, $randomizer));
        self::assertSame(250.0, $backoff->delayMs(10, $randomizer));
    }

    public function testRejectsNegativeBase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LinearBackoff(baseMs: -1.0);
    }

    public function testRejectsNegativeCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LinearBackoff(baseMs: 100.0, capMs: -1.0);
    }
}
