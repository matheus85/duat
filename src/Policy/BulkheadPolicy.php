<?php

declare(strict_types=1);

namespace Duat\Policy;

use Duat\Context;
use Duat\Contract\Policy;
use Duat\Contract\StateStore;
use Duat\Event\CallRejected;
use Duat\Event\RejectionReason;
use Duat\Exception\BulkheadFullException;
use InvalidArgumentException;

/**
 * Caps how many calls run concurrently against a resource. When the limit
 * is reached new calls fail immediately with BulkheadFullException: there
 * is no queue, because blocking a synchronous PHP worker to wait for a
 * slot would only move the pile-up somewhere worse.
 *
 * Active calls are counted at `duat:bh:{name}:active`. The counter takes a
 * safety lease of $leaseSeconds when created, so slots leaked by a process
 * that died mid-call heal themselves. The flip side: if the resource stays
 * under uninterrupted concurrency for longer than the lease, the counter
 * can reset early and briefly over-admit. Set $leaseSeconds comfortably
 * above your slowest expected call.
 */
final class BulkheadPolicy implements Policy
{
    public function __construct(
        private readonly StateStore $store,
        private readonly int $maxConcurrent,
        private readonly int $leaseSeconds = 60,
    ) {
        if ($maxConcurrent < 1) {
            throw new InvalidArgumentException(sprintf('$maxConcurrent must be at least 1, got %d.', $maxConcurrent));
        }

        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException(sprintf('$leaseSeconds must be at least 1, got %d.', $leaseSeconds));
        }
    }

    public function execute(callable $next, Context $context): mixed
    {
        $key = $this->key($context);
        $slot = $this->store->increment($key, ttlSeconds: $this->leaseSeconds);

        if ($slot > $this->maxConcurrent) {
            $this->store->increment($key, by: -1);
            $context->dispatch(new CallRejected($context->name, RejectionReason::BulkheadFull));

            throw new BulkheadFullException($context->name, $this->maxConcurrent);
        }

        try {
            return $next($context);
        } finally {
            // A negative counter means the lease expired mid-call; drop the
            // key so the next admission starts a clean count.
            if ($this->store->increment($key, by: -1) < 0) {
                $this->store->delete($key);
            }
        }
    }

    private function key(Context $context): string
    {
        return 'duat:bh:' . $context->name . ':active';
    }
}
