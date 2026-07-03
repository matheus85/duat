<?php

declare(strict_types=1);

namespace Duat\Event;

final readonly class CircuitClosed
{
    public function __construct(public string $name)
    {
    }
}
