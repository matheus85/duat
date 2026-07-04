<?php

declare(strict_types=1);

namespace Duat\Backoff;

/**
 * Names a backoff strategy where an instance cannot be expressed, e.g.
 * inside attribute arguments, which only allow constant expressions.
 */
enum BackoffType
{
    case Constant;
    case Linear;
    case Exponential;

    public function build(float $baseMs, ?float $capMs, bool $jitter): Backoff
    {
        return match ($this) {
            self::Constant => Backoff::constant($baseMs),
            self::Linear => Backoff::linear($baseMs, $capMs),
            self::Exponential => Backoff::exponential($baseMs, $capMs, $jitter),
        };
    }
}
