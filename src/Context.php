<?php

declare(strict_types=1);

namespace Duat;

use Duat\Contract\Clock;
use Duat\Contract\Randomizer;

/**
 * Immutable execution context passed through the policy pipeline.
 */
final readonly class Context
{
    public float $startedAt;

    /**
     * @param int $attempt Current attempt number, 1-based.
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $name,
        public Clock $clock,
        public Randomizer $randomizer,
        public int $attempt = 1,
        ?float $startedAt = null,
        public array $metadata = [],
    ) {
        $this->startedAt = $startedAt ?? $clock->now();
    }

    public function withAttempt(int $attempt): self
    {
        return new self(
            name: $this->name,
            clock: $this->clock,
            randomizer: $this->randomizer,
            attempt: $attempt,
            startedAt: $this->startedAt,
            metadata: $this->metadata,
        );
    }
}
