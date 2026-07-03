<?php

declare(strict_types=1);

namespace Duat\Contract;

interface Randomizer
{
    /**
     * Uniformly distributed float in [0, 1).
     */
    public function float(): float;
}
