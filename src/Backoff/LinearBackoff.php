<?php

declare(strict_types=1);

namespace Duat\Backoff;

use Duat\Contract\Randomizer;

final class LinearBackoff extends Backoff
{
    public function __construct(
        private readonly float $baseMs,
        private readonly ?float $capMs = null,
    ) {
        self::assertNonNegative($baseMs, '$baseMs');

        if ($capMs !== null) {
            self::assertNonNegative($capMs, '$capMs');
        }
    }

    public function delayMs(int $attempt, Randomizer $randomizer): float
    {
        $delay = $this->baseMs * $attempt;

        return $this->capMs === null ? $delay : min($delay, $this->capMs);
    }
}
