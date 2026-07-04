<?php

declare(strict_types=1);

namespace Duat\Policy;

use Duat\Context;
use Duat\Contract\Policy;
use Duat\Contract\StateStore;
use Duat\Event\CallRejected;
use Duat\Event\RejectionReason;
use Duat\Exception\RateLimitExceededException;
use InvalidArgumentException;

/**
 * Fixed window rate limiter: one counter per window of $perSeconds at
 * `duat:rl:{name}:{window}`, shared by every worker on the store. Rejected
 * attempts also count, and the exception carries how long to wait for the
 * next window.
 *
 * Fixed windows are simple and cheap, with one honest caveat: a burst can
 * reach up to twice the limit right around a window boundary. If that ever
 * hurts, a sliding variant can take its place.
 */
final class RateLimiterPolicy implements Policy
{
    private const TTL_MARGIN_SECONDS = 5;

    public function __construct(
        private readonly StateStore $store,
        private readonly int $maxCalls,
        private readonly int $perSeconds,
    ) {
        if ($maxCalls < 1) {
            throw new InvalidArgumentException(sprintf('$maxCalls must be at least 1, got %d.', $maxCalls));
        }

        if ($perSeconds < 1) {
            throw new InvalidArgumentException(sprintf('$perSeconds must be at least 1, got %d.', $perSeconds));
        }
    }

    public function execute(callable $next, Context $context): mixed
    {
        $now = $context->clock->now();
        $window = (int) floor($now / $this->perSeconds);
        $key = sprintf('duat:rl:%s:%d', $context->name, $window);

        $count = $this->store->increment($key, ttlSeconds: $this->perSeconds + self::TTL_MARGIN_SECONDS);

        if ($count > $this->maxCalls) {
            $retryAfter = ($window + 1) * $this->perSeconds - $now;
            $context->dispatch(new CallRejected($context->name, RejectionReason::RateLimited));

            throw new RateLimitExceededException($context->name, $this->maxCalls, $this->perSeconds, $retryAfter);
        }

        return $next($context);
    }
}
