<?php

declare(strict_types=1);

namespace Duat\Attributes;

use Attribute;
use Throwable;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CircuitBreaker
{
    /**
     * @param list<class-string<Throwable>> $recordOn
     */
    public function __construct(
        public float $failureRateThreshold = 0.5,
        public int $minimumCalls = 10,
        public int $windowSeconds = 60,
        public int $cooldownSeconds = 30,
        public int $halfOpenMaxCalls = 1,
        public array $recordOn = [Throwable::class],
    ) {
    }
}
