<?php

declare(strict_types=1);

namespace Duat;

use Duat\Contract\Clock;
use Duat\Contract\Randomizer;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Immutable execution context passed through the policy pipeline.
 */
final readonly class Context
{
    public float $startedAt;

    /**
     * @param int $attempt Current attempt number, 1-based.
     * @param array<string, mixed> $metadata
     * @param float|null $deadline Epoch seconds after which the work is
     *        considered late. Set by TimeoutPolicy.
     */
    public function __construct(
        public string $name,
        public Clock $clock,
        public Randomizer $randomizer,
        public int $attempt = 1,
        ?float $startedAt = null,
        public array $metadata = [],
        public ?EventDispatcherInterface $events = null,
        public ?float $deadline = null,
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
            events: $this->events,
            deadline: $this->deadline,
        );
    }

    public function withDeadline(float $deadline): self
    {
        return new self(
            name: $this->name,
            clock: $this->clock,
            randomizer: $this->randomizer,
            attempt: $this->attempt,
            startedAt: $this->startedAt,
            metadata: $this->metadata,
            events: $this->events,
            deadline: $deadline,
        );
    }

    /**
     * Seconds left until the deadline, clamped at zero. Null when no
     * deadline is set.
     */
    public function remainingBudget(): ?float
    {
        return $this->deadline === null
            ? null
            : max(0.0, $this->deadline - $this->clock->now());
    }

    public function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
