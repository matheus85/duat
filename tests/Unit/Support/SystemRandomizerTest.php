<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Support;

use Duat\Support\SystemRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer as PhpRandomizer;

#[CoversClass(SystemRandomizer::class)]
final class SystemRandomizerTest extends TestCase
{
    public function testProducesValuesWithinRange(): void
    {
        $randomizer = new SystemRandomizer();

        for ($i = 0; $i < 1_000; $i++) {
            $value = $randomizer->float();

            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    public function testIsDeterministicWithSeededEngine(): void
    {
        $first = new SystemRandomizer(new PhpRandomizer(new Mt19937(42)));
        $second = new SystemRandomizer(new PhpRandomizer(new Mt19937(42)));

        self::assertSame($first->float(), $second->float());
    }
}
