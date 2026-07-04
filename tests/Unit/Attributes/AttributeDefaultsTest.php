<?php

declare(strict_types=1);

namespace Duat\Tests\Unit\Attributes;

use Duat\Attributes\Bulkhead;
use Duat\Attributes\CircuitBreaker;
use Duat\Attributes\Fallback;
use Duat\Attributes\RateLimiter;
use Duat\Attributes\Retry;
use Duat\Attributes\Timeout;
use Duat\Backoff\BackoffType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Attribute defaults are public API: changing one silently changes the
 * behavior of every annotated method out there, so they are pinned here.
 */
#[CoversClass(Retry::class)]
#[CoversClass(CircuitBreaker::class)]
#[CoversClass(Timeout::class)]
#[CoversClass(Bulkhead::class)]
#[CoversClass(RateLimiter::class)]
#[CoversClass(Fallback::class)]
final class AttributeDefaultsTest extends TestCase
{
    public function testRetryDefaults(): void
    {
        $retry = new Retry();

        self::assertSame(3, $retry->maxAttempts);
        self::assertSame(200.0, $retry->backoffMs);
        self::assertSame(10_000.0, $retry->capMs);
        self::assertTrue($retry->jitter);
        self::assertSame(BackoffType::Exponential, $retry->backoff);
        self::assertSame([Throwable::class], $retry->retryOn);
        self::assertSame([], $retry->abortOn);
    }

    public function testCircuitBreakerDefaults(): void
    {
        $breaker = new CircuitBreaker();

        self::assertSame(0.5, $breaker->failureRateThreshold);
        self::assertSame(10, $breaker->minimumCalls);
        self::assertSame(60, $breaker->windowSeconds);
        self::assertSame(30, $breaker->cooldownSeconds);
        self::assertSame(1, $breaker->halfOpenMaxCalls);
        self::assertSame([Throwable::class], $breaker->recordOn);
    }

    public function testTimeoutDefaults(): void
    {
        $timeout = new Timeout(seconds: 5.0);

        self::assertSame(5.0, $timeout->seconds);
        self::assertFalse($timeout->throwOnLateSuccess);
    }

    public function testBulkheadDefaults(): void
    {
        $bulkhead = new Bulkhead(maxConcurrent: 8);

        self::assertSame(8, $bulkhead->maxConcurrent);
        self::assertSame(60, $bulkhead->leaseSeconds);
    }

    public function testRateLimiterCarriesItsConfiguration(): void
    {
        $limiter = new RateLimiter(maxCalls: 100, perSeconds: 60);

        self::assertSame(100, $limiter->maxCalls);
        self::assertSame(60, $limiter->perSeconds);
    }

    public function testFallbackDefaults(): void
    {
        $fallback = new Fallback(method: 'degrade');

        self::assertSame('degrade', $fallback->method);
        self::assertSame([Throwable::class], $fallback->on);
    }
}
