<?php

declare(strict_types=1);

namespace Duat\Backoff;

use Duat\Contract\Randomizer;

/**
 * Exponential backoff with optional full jitter: the delay is drawn
 * uniformly from [0, base), where base doubles on every attempt up to the
 * cap. See "Exponential Backoff and Jitter" (AWS Architecture Blog).
 */
final class ExponentialBackoff extends Backoff
{
    public function __construct(
        private readonly float $baseMs,
        private readonly ?float $capMs = null,
        private readonly bool $jitter = true,
    ) {
        self::assertNonNegative($baseMs, '$baseMs');

        if ($capMs !== null) {
            self::assertNonNegative($capMs, '$capMs');
        }
    }

    public function delayMs(int $attempt, Randomizer $randomizer): float
    {
        $base = $this->baseMs * (2.0 ** ($attempt - 1));

        if ($this->capMs !== null) {
            $base = min($base, $this->capMs);
        }

        return $this->jitter ? $randomizer->float() * $base : $base;
    }
}
