<?php

declare(strict_types=1);

namespace Duat\Attributes;

use Attribute;
use Duat\Backoff\BackoffType;
use Throwable;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Retry
{
    /**
     * @param list<class-string<Throwable>> $retryOn
     * @param list<class-string<Throwable>> $abortOn
     */
    public function __construct(
        public int $maxAttempts = 3,
        public float $backoffMs = 200.0,
        public ?float $capMs = 10_000.0,
        public bool $jitter = true,
        public BackoffType $backoff = BackoffType::Exponential,
        public array $retryOn = [Throwable::class],
        public array $abortOn = [],
    ) {
    }
}
