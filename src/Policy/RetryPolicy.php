<?php

declare(strict_types=1);

namespace Duat\Policy;

use Closure;
use Duat\Backoff\Backoff;
use Duat\Context;
use Duat\Contract\Policy;
use Duat\Event\RetryAttempted;
use Duat\Exception\CircuitOpenException;
use Duat\Exception\RetryExhaustedException;
use InvalidArgumentException;
use Throwable;

final class RetryPolicy implements Policy
{
    private readonly ?Closure $onRetry;

    /**
     * @param int $maxAttempts Total attempts including the first one.
     * @param list<class-string<Throwable>> $retryOn
     * @param list<class-string<Throwable>> $abortOn Takes precedence over
     *        $retryOn. CircuitOpenException is always aborted: hammering an
     *        open circuit defeats its purpose.
     * @param (callable(Throwable, Context): void)|null $onRetry Called before
     *        each wait, after RetryAttempted is dispatched.
     */
    public function __construct(
        private readonly int $maxAttempts,
        private readonly Backoff $backoff,
        private readonly array $retryOn = [Throwable::class],
        private readonly array $abortOn = [],
        ?callable $onRetry = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('$maxAttempts must be at least 1, got %d.', $maxAttempts));
        }

        $this->onRetry = $onRetry === null ? null : $onRetry(...);
    }

    public function execute(callable $next, Context $context): mixed
    {
        $attempt = $context->attempt;

        while (true) {
            $current = $context->withAttempt($attempt);

            try {
                return $next($current);
            } catch (Throwable $exception) {
                if (!$this->isRetryable($exception)) {
                    throw $exception;
                }

                if ($attempt >= $this->maxAttempts) {
                    throw new RetryExhaustedException($attempt, $exception);
                }

                $delayMs = $this->backoff->delayMs($attempt, $current->randomizer);

                // Retrying past the deadline only burns budget, so give up
                // with the real failure instead.
                $budget = $current->remainingBudget();
                if ($budget !== null && $delayMs / 1_000.0 >= $budget) {
                    throw $exception;
                }

                $current->dispatch(new RetryAttempted($current->name, $attempt, $delayMs, $exception));

                if ($this->onRetry !== null) {
                    ($this->onRetry)($exception, $current);
                }

                $current->clock->sleep($delayMs / 1_000.0);
                $attempt++;
            }
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof CircuitOpenException) {
            return false;
        }

        foreach ($this->abortOn as $abort) {
            if ($exception instanceof $abort) {
                return false;
            }
        }

        foreach ($this->retryOn as $retry) {
            if ($exception instanceof $retry) {
                return true;
            }
        }

        return false;
    }
}
