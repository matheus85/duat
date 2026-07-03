<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use Duat\Contract\Clock;

/**
 * Deterministic clock for tests. Sleeping advances the internal time
 * instantly and records the requested duration.
 */
final class FakeClock implements Clock
{
    /** @var list<float> */
    private array $sleeps = [];

    public function __construct(private float $now = 0.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * @return list<float>
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
