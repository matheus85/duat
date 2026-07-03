<?php

declare(strict_types=1);

namespace Duat\Contract;

interface Clock
{
    /**
     * Epoch seconds with microsecond precision.
     */
    public function now(): float;

    public function sleep(float $seconds): void;
}
