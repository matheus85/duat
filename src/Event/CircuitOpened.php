<?php

declare(strict_types=1);

namespace Duat\Event;

/**
 * Emitted when the circuit opens. The failure rate is null when the opening
 * came from a failed half-open probe instead of a window evaluation.
 */
final readonly class CircuitOpened
{
    public function __construct(
        public string $name,
        public ?float $failureRate,
    ) {
    }
}
