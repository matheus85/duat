<?php

declare(strict_types=1);

namespace Duat\Backoff;

use Duat\Contract\Randomizer;
use InvalidArgumentException;

abstract class Backoff
{
    public static function constant(float $ms): ConstantBackoff
    {
        return new ConstantBackoff($ms);
    }

    public static function linear(float $baseMs, ?float $capMs = null): LinearBackoff
    {
        return new LinearBackoff($baseMs, $capMs);
    }

    public static function exponential(float $baseMs, ?float $capMs = null, bool $jitter = true): ExponentialBackoff
    {
        return new ExponentialBackoff($baseMs, $capMs, $jitter);
    }

    /**
     * Delay in milliseconds to wait after the given failed attempt, 1-based.
     */
    abstract public function delayMs(int $attempt, Randomizer $randomizer): float;

    final protected static function assertNonNegative(float $value, string $name): void
    {
        if ($value < 0.0) {
            throw new InvalidArgumentException(sprintf('%s must be non-negative, got %f.', $name, $value));
        }
    }
}
