<?php

declare(strict_types=1);

namespace Duat\Event;

use Throwable;

/**
 * Emitted right before the fallback handler runs.
 */
final readonly class FallbackExecuted
{
    public function __construct(
        public string $name,
        public Throwable $exception,
    ) {
    }
}
