<?php

declare(strict_types=1);

namespace Duat\Event;

use Throwable;

/**
 * Emitted after a failed attempt that will be retried, before the wait.
 */
final readonly class RetryAttempted
{
    public function __construct(
        public string $name,
        public int $attempt,
        public float $delayMs,
        public Throwable $exception,
    ) {
    }
}
