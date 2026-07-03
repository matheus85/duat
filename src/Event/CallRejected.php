<?php

declare(strict_types=1);

namespace Duat\Event;

/**
 * Emitted when a call is refused before reaching the protected callable.
 */
final readonly class CallRejected
{
    public function __construct(
        public string $name,
        public RejectionReason $reason,
    ) {
    }
}
