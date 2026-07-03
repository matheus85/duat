<?php

declare(strict_types=1);

namespace Duat\Event;

/**
 * Emitted when a call finishes successfully after its deadline and the
 * timeout policy is configured to return late results instead of throwing.
 */
final readonly class DeadlineExceeded
{
    public function __construct(
        public string $name,
        public float $timeoutSeconds,
        public float $elapsedSeconds,
    ) {
    }
}
