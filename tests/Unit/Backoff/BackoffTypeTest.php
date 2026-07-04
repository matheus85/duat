<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Backoff;

use Duat\Backoff\BackoffType;
use Duat\Backoff\ConstantBackoff;
use Duat\Backoff\ExponentialBackoff;
use Duat\Backoff\LinearBackoff;
use Duat\Tests\Support\FakeRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackoffType::class)]
final class BackoffTypeTest extends TestCase
{
    public function testBuildsConstant(): void
    {
        $backoff = BackoffType::Constant->build(150.0, null, false);

        self::assertInstanceOf(ConstantBackoff::class, $backoff);
        self::assertSame(150.0, $backoff->delayMs(7, new FakeRandomizer()));
    }

    public function testBuildsLinearWithCap(): void
    {
        $backoff = BackoffType::Linear->build(100.0, 250.0, false);

        self::assertInstanceOf(LinearBackoff::class, $backoff);
        self::assertSame(250.0, $backoff->delayMs(10, new FakeRandomizer()));
    }

    public function testBuildsExponentialWithJitter(): void
    {
        $backoff = BackoffType::Exponential->build(200.0, 500.0, true);

        self::assertInstanceOf(ExponentialBackoff::class, $backoff);
        self::assertSame(250.0, $backoff->delayMs(5, new FakeRandomizer(0.5)));
    }
}
