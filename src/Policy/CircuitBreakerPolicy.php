<?php

declare(strict_types=1);

namespace Duat\Policy;

use Duat\Context;
use Duat\Contract\Policy;
use Duat\Contract\StateStore;
use Duat\Event\CallRejected;
use Duat\Event\CircuitClosed;
use Duat\Event\CircuitHalfOpened;
use Duat\Event\CircuitOpened;
use Duat\Event\RejectionReason;
use Duat\Exception\CircuitOpenException;
use InvalidArgumentException;
use Throwable;

/**
 * Circuit breaker over a time-based sliding window of per-second buckets.
 *
 * State lives in the StateStore under `duat:cb:{name}:`:
 *   - state            current CircuitState value; missing means closed
 *   - opened_at        epoch seconds of the last transition to open
 *   - generation       window generation, bumped on close so old buckets
 *                      become invisible without a range delete
 *   - half_open_calls  probes admitted in the current half-open phase
 *   - probe_lock       lease electing the worker that flips open to
 *                      half-open, so probes do not stampede
 *   - w:{gen}:s:{sec} / w:{gen}:f:{sec}  success/failure buckets
 *
 * Transitions use setIfNotExists, atomic on backends like Redis. On
 * non-atomic stores concurrent workers may race benignly: the worst case is
 * an extra probe, never a lost failure count.
 */
final class CircuitBreakerPolicy implements Policy
{
    private const TTL_MARGIN_SECONDS = 5;

    /**
     * @param float $failureRateThreshold Failure rate in (0, 1] that opens
     *        the circuit.
     * @param int $minimumCalls Calls the window must hold before the rate
     *        is evaluated.
     * @param list<class-string<Throwable>> $recordOn What counts as failure;
     *        anything else passes through without touching the window.
     */
    public function __construct(
        private readonly StateStore $store,
        private readonly float $failureRateThreshold = 0.5,
        private readonly int $minimumCalls = 10,
        private readonly int $windowSeconds = 60,
        private readonly int $cooldownSeconds = 30,
        private readonly int $halfOpenMaxCalls = 1,
        private readonly array $recordOn = [Throwable::class],
    ) {
        if ($failureRateThreshold <= 0.0 || $failureRateThreshold > 1.0) {
            throw new InvalidArgumentException(
                sprintf('$failureRateThreshold must be within (0, 1], got %f.', $failureRateThreshold),
            );
        }

        foreach ([
            '$minimumCalls' => $minimumCalls,
            '$windowSeconds' => $windowSeconds,
            '$cooldownSeconds' => $cooldownSeconds,
            '$halfOpenMaxCalls' => $halfOpenMaxCalls,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be at least 1, got %d.', $name, $value));
            }
        }
    }

    public function execute(callable $next, Context $context): mixed
    {
        $state = $this->state($context);

        if ($state === CircuitState::Open) {
            $state = $this->maybeHalfOpen($context);

            if ($state === CircuitState::Open) {
                $this->reject($context);
            }
        }

        if ($state === CircuitState::HalfOpen) {
            return $this->probe($next, $context);
        }

        return $this->call($next, $context);
    }

    private function call(callable $next, Context $context): mixed
    {
        try {
            $result = $next($context);
        } catch (Throwable $exception) {
            if ($this->isRecordable($exception)) {
                $this->recordFailure($context);
            }

            throw $exception;
        }

        $this->store->increment($this->bucketKey($context, 's'), ttlSeconds: $this->bucketTtl());

        return $result;
    }

    private function probe(callable $next, Context $context): mixed
    {
        $slot = $this->store->increment(
            $this->key($context, 'half_open_calls'),
            ttlSeconds: $this->cooldownSeconds,
        );

        if ($slot > $this->halfOpenMaxCalls) {
            $this->reject($context);
        }

        try {
            $result = $next($context);
        } catch (Throwable $exception) {
            if ($this->isRecordable($exception)) {
                $this->open($context, failureRate: null);
            } else {
                // A neutral failure proves nothing, so hand the slot back
                // to the next probe candidate.
                $this->store->increment($this->key($context, 'half_open_calls'), by: -1);
            }

            throw $exception;
        }

        if ($slot === $this->halfOpenMaxCalls && $this->state($context) === CircuitState::HalfOpen) {
            $this->close($context);
        }

        return $result;
    }

    private function maybeHalfOpen(Context $context): CircuitState
    {
        $openedAt = $this->store->get($this->key($context, 'opened_at'));

        // A missing opened_at means the store lost it; failing towards a
        // probe beats staying open forever.
        if ($openedAt !== null && $context->clock->now() - (float) $openedAt < $this->cooldownSeconds) {
            return CircuitState::Open;
        }

        $elected = $this->store->setIfNotExists(
            $this->key($context, 'probe_lock'),
            '1',
            ttlSeconds: $this->cooldownSeconds,
        );

        if ($elected) {
            $this->store->delete($this->key($context, 'half_open_calls'));
            $this->store->set($this->key($context, 'state'), CircuitState::HalfOpen->value);
            $context->dispatch(new CircuitHalfOpened($context->name));
        }

        return CircuitState::HalfOpen;
    }

    private function recordFailure(Context $context): void
    {
        $this->store->increment($this->bucketKey($context, 'f'), ttlSeconds: $this->bucketTtl());

        [$successes, $failures] = $this->windowCounts($context);
        $total = $successes + $failures;

        if ($total < $this->minimumCalls) {
            return;
        }

        $failureRate = $failures / $total;

        if ($failureRate >= $this->failureRateThreshold) {
            $this->open($context, $failureRate);
        }
    }

    private function open(Context $context, ?float $failureRate): void
    {
        $this->store->set($this->key($context, 'state'), CircuitState::Open->value);
        $this->store->set($this->key($context, 'opened_at'), (string) $context->clock->now());
        $this->store->delete($this->key($context, 'half_open_calls'));
        $this->store->delete($this->key($context, 'probe_lock'));
        $context->dispatch(new CircuitOpened($context->name, $failureRate));
    }

    private function close(Context $context): void
    {
        $this->store->set($this->key($context, 'state'), CircuitState::Closed->value);
        $this->store->delete($this->key($context, 'opened_at'));
        $this->store->delete($this->key($context, 'half_open_calls'));
        $this->store->delete($this->key($context, 'probe_lock'));
        $this->store->increment($this->key($context, 'generation'));
        $context->dispatch(new CircuitClosed($context->name));
    }

    private function reject(Context $context): never
    {
        $context->dispatch(new CallRejected($context->name, RejectionReason::CircuitOpen));

        throw new CircuitOpenException($context->name);
    }

    private function state(Context $context): CircuitState
    {
        $raw = $this->store->get($this->key($context, 'state'));

        return $raw === null
            ? CircuitState::Closed
            : (CircuitState::tryFrom($raw) ?? CircuitState::Closed);
    }

    /**
     * @return array{int, int} Successes and failures inside the window.
     */
    private function windowCounts(Context $context): array
    {
        $generation = $this->generation($context);
        $last = (int) floor($context->clock->now());
        $successes = 0;
        $failures = 0;

        for ($second = $last - $this->windowSeconds + 1; $second <= $last; $second++) {
            $successes += (int) ($this->store->get($this->windowKey($context, $generation, 's', $second)) ?? '0');
            $failures += (int) ($this->store->get($this->windowKey($context, $generation, 'f', $second)) ?? '0');
        }

        return [$successes, $failures];
    }

    private function isRecordable(Throwable $exception): bool
    {
        foreach ($this->recordOn as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function bucketKey(Context $context, string $kind): string
    {
        return $this->windowKey(
            $context,
            $this->generation($context),
            $kind,
            (int) floor($context->clock->now()),
        );
    }

    private function windowKey(Context $context, string $generation, string $kind, int $second): string
    {
        return sprintf('duat:cb:%s:w:%s:%s:%d', $context->name, $generation, $kind, $second);
    }

    private function generation(Context $context): string
    {
        return $this->store->get($this->key($context, 'generation')) ?? '0';
    }

    private function bucketTtl(): int
    {
        return $this->windowSeconds + self::TTL_MARGIN_SECONDS;
    }

    private function key(Context $context, string $suffix): string
    {
        return 'duat:cb:' . $context->name . ':' . $suffix;
    }
}
