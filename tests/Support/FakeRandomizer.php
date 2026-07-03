<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use Duat\Contract\Randomizer;
use InvalidArgumentException;

/**
 * Returns a fixed sequence of values, cycling when exhausted.
 */
final class FakeRandomizer implements Randomizer
{
    /** @var non-empty-list<float> */
    private array $values;

    private int $calls = 0;

    public function __construct(float ...$values)
    {
        foreach ($values as $value) {
            if ($value < 0.0 || $value >= 1.0) {
                throw new InvalidArgumentException(sprintf('Value %f is outside [0, 1).', $value));
            }
        }

        $this->values = $values === [] ? [0.0] : array_values($values);
    }

    public function float(): float
    {
        $index = $this->calls % count($this->values);
        ++$this->calls;

        return $this->values[$index];
    }
}
