<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Support;

use Duat\Tests\Support\FakeRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakeRandomizerTest extends TestCase
{
    public function testReturnsGivenValuesInOrder(): void
    {
        $randomizer = new FakeRandomizer(0.1, 0.5, 0.9);

        self::assertSame(0.1, $randomizer->float());
        self::assertSame(0.5, $randomizer->float());
        self::assertSame(0.9, $randomizer->float());
    }

    public function testCyclesWhenSequenceIsExhausted(): void
    {
        $randomizer = new FakeRandomizer(0.25, 0.75);

        $randomizer->float();
        $randomizer->float();

        self::assertSame(0.25, $randomizer->float());
    }

    public function testDefaultsToZeroWithoutValues(): void
    {
        self::assertSame(0.0, (new FakeRandomizer())->float());
    }

    public function testRejectsValueBelowRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FakeRandomizer(-0.1);
    }

    public function testRejectsValueAtUpperBound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FakeRandomizer(1.0);
    }
}
