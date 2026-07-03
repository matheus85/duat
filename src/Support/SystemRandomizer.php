<?php

declare(strict_types=1);

namespace Duat\Support;

use Duat\Contract\Randomizer;
use Random\Randomizer as PhpRandomizer;

final class SystemRandomizer implements Randomizer
{
    private readonly PhpRandomizer $randomizer;

    public function __construct(?PhpRandomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new PhpRandomizer();
    }

    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }
}
