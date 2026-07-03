<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Backoff;

use Duat\Backoff\Backoff;
use Duat\Backoff\ConstantBackoff;
use Duat\Backoff\ExponentialBackoff;
use Duat\Backoff\LinearBackoff;
use Duat\Tests\Support\FakeRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Backoff::class)]
final class BackoffTest extends TestCase
{
    public function testConstantFactoryBuildsEquivalentInstance(): void
    {
        $backoff = Backoff::constant(150.0);

        self::assertInstanceOf(ConstantBackoff::class, $backoff);
        self::assertSame(150.0, $backoff->delayMs(3, new FakeRandomizer()));
    }

    public function testLinearFactoryPassesBaseAndCapThrough(): void
    {
        $backoff = Backoff::linear(baseMs: 100.0, capMs: 250.0);

        self::assertInstanceOf(LinearBackoff::class, $backoff);
        self::assertSame(250.0, $backoff->delayMs(10, new FakeRandomizer()));
    }

    public function testExponentialFactoryPassesEverythingThrough(): void
    {
        $backoff = Backoff::exponential(baseMs: 200.0, capMs: 500.0, jitter: false);

        self::assertInstanceOf(ExponentialBackoff::class, $backoff);
        self::assertSame(500.0, $backoff->delayMs(10, new FakeRandomizer()));
    }
}
