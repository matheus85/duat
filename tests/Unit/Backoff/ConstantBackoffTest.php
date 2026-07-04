<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Backoff;

use Duat\Backoff\ConstantBackoff;
use Duat\Tests\Support\FakeRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantBackoff::class)]
final class ConstantBackoffTest extends TestCase
{
    public function testDelayIsTheSameForEveryAttempt(): void
    {
        $backoff = new ConstantBackoff(150.0);
        $randomizer = new FakeRandomizer();

        self::assertSame(150.0, $backoff->delayMs(1, $randomizer));
        self::assertSame(150.0, $backoff->delayMs(5, $randomizer));
        self::assertSame(150.0, $backoff->delayMs(50, $randomizer));
    }

    public function testZeroDelayIsValid(): void
    {
        self::assertSame(0.0, (new ConstantBackoff(0.0))->delayMs(1, new FakeRandomizer()));
    }

    public function testRejectsNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConstantBackoff(-1.0);
    }
}
