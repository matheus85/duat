<?php

declare(strict_types=1);

namespace Duat\Policy;

use Duat\Context;
use Duat\Contract\Policy;
use Duat\Event\DeadlineExceeded;
use Duat\Exception\TimeoutExceededException;
use InvalidArgumentException;

/**
 * Deadline-based timeout. Synchronous PHP cannot interrupt a blocking
 * callable, so this policy does not pretend to: it registers a deadline in
 * the context for inner layers (retry stops when the budget is gone) and
 * checks the clock once the call returns. Real enforcement belongs in the
 * client, e.g. cURL timeout options fed from Context::remainingBudget().
 */
final class TimeoutPolicy implements Policy
{
    public function __construct(
        private readonly float $seconds,
        private readonly bool $throwOnLateSuccess = false,
    ) {
        if ($seconds <= 0.0) {
            throw new InvalidArgumentException(sprintf('$seconds must be positive, got %f.', $seconds));
        }
    }

    public function execute(callable $next, Context $context): mixed
    {
        $start = $context->clock->now();
        $deadline = $start + $this->seconds;

        // A tighter deadline set by an outer layer wins over this one.
        if ($context->deadline !== null) {
            $deadline = min($deadline, $context->deadline);
        }

        $result = $next($context->withDeadline($deadline));

        if ($context->clock->now() > $deadline) {
            $allowed = $deadline - $start;
            $elapsed = $context->clock->now() - $start;

            if ($this->throwOnLateSuccess) {
                throw new TimeoutExceededException($context->name, $allowed, $elapsed);
            }

            $context->dispatch(new DeadlineExceeded($context->name, $allowed, $elapsed));
        }

        return $result;
    }
}
