<?php

declare(strict_types=1);

namespace Duat\Backoff;

use Duat\Contract\Randomizer;

final class ConstantBackoff extends Backoff
{
    public function __construct(private readonly float $ms)
    {
        self::assertNonNegative($ms, '$ms');
    }

    public function delayMs(int $attempt, Randomizer $randomizer): float
    {
        return $this->ms;
    }
}
