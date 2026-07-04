<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\CircuitBreaker;
use Duat\Attributes\Fallback;
use Duat\Attributes\Retry;
use Duat\Backoff\BackoffType;
use RuntimeException;
use Throwable;

final class PaymentService
{
    public int $calls = 0;

    public function __construct(private readonly int $failFirst = 0)
    {
    }

    #[Audited]
    #[Retry(maxAttempts: 3, backoffMs: 100.0, backoff: BackoffType::Constant, jitter: false)]
    public function charge(string $order, int $amount): string
    {
        $this->calls++;

        if ($this->calls <= $this->failFirst) {
            throw new RuntimeException('gateway down ' . $this->calls);
        }

        return sprintf('charged %s %d', $order, $amount);
    }

    #[Retry(maxAttempts: 2, backoffMs: 50.0, backoff: BackoffType::Constant, jitter: false)]
    #[Fallback(method: 'pending')]
    public function capture(string $order): string
    {
        $this->calls++;

        throw new RuntimeException('capture failed');
    }

    public function pending(string $order, Throwable $exception): string
    {
        return sprintf('pending %s (%s)', $order, $exception::class);
    }

    #[Retry(maxAttempts: 3, backoffMs: 10.0, backoff: BackoffType::Constant, jitter: false)]
    #[CircuitBreaker(failureRateThreshold: 1.0, minimumCalls: 1)]
    public function fragile(): string
    {
        $this->calls++;

        throw new RuntimeException('fragile down');
    }

    public function plain(): string
    {
        return 'plain untouched';
    }
}
