<?php

declare(strict_types=1);

namespace Duat;

use Closure;
use Duat\Backoff\Backoff;
use Duat\Contract\Clock;
use Duat\Contract\Policy;
use Duat\Contract\Randomizer;
use Duat\Contract\StateStore;
use Duat\Policy\CircuitBreakerPolicy;
use Duat\Policy\FallbackPolicy;
use Duat\Policy\RetryPolicy;
use Duat\Policy\TimeoutPolicy;
use Duat\Store\InMemoryStore;
use Duat\Support\SystemClock;
use Duat\Support\SystemRandomizer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Fluent entry point. Builders are immutable: every method returns a new
 * instance, so a configured chain can be stored and reused safely.
 *
 * Method call order defines the pipeline order, outermost first: in
 * retry()->circuitBreaker()->timeout(), retry wraps the breaker, which
 * wraps the timeout, which wraps the callable. fallback() is the one
 * exception: it always becomes the outermost layer wherever it appears.
 *
 * Without an explicit store() the circuit breaker state lives in a
 * process-wide InMemoryStore, which does not cross PHP-FPM workers. Point
 * store() at a RedisStore or another shared StateStore in production.
 */
final class Duat
{
    private static ?StateStore $defaultStore = null;

    /**
     * @param list<Closure(StateStore): Policy> $layers
     * @param list<Policy> $fallbacks
     */
    private function __construct(
        private readonly string $name,
        private readonly array $layers = [],
        private readonly array $fallbacks = [],
        private readonly ?StateStore $store = null,
        private readonly ?Clock $clock = null,
        private readonly ?Randomizer $randomizer = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    public static function for(string $name): self
    {
        return new self($name);
    }

    /**
     * @param list<class-string<Throwable>> $retryOn
     * @param list<class-string<Throwable>> $abortOn
     * @param (callable(Throwable, Context): void)|null $onRetry
     */
    public function retry(
        int $maxAttempts = 3,
        ?Backoff $backoff = null,
        array $retryOn = [Throwable::class],
        array $abortOn = [],
        ?callable $onRetry = null,
    ): self {
        $policy = new RetryPolicy(
            maxAttempts: $maxAttempts,
            backoff: $backoff ?? Backoff::exponential(baseMs: 200.0, capMs: 10_000.0),
            retryOn: $retryOn,
            abortOn: $abortOn,
            onRetry: $onRetry,
        );

        return $this->withLayer(static fn (StateStore $store): Policy => $policy);
    }

    /**
     * @param list<class-string<Throwable>> $recordOn
     */
    public function circuitBreaker(
        float $failureRateThreshold = 0.5,
        int $minimumCalls = 10,
        int $windowSeconds = 60,
        int $cooldownSeconds = 30,
        int $halfOpenMaxCalls = 1,
        array $recordOn = [Throwable::class],
    ): self {
        return $this->withLayer(static fn (StateStore $store): Policy => new CircuitBreakerPolicy(
            store: $store,
            failureRateThreshold: $failureRateThreshold,
            minimumCalls: $minimumCalls,
            windowSeconds: $windowSeconds,
            cooldownSeconds: $cooldownSeconds,
            halfOpenMaxCalls: $halfOpenMaxCalls,
            recordOn: $recordOn,
        ));
    }

    public function timeout(float $seconds, bool $throwOnLateSuccess = false): self
    {
        $policy = new TimeoutPolicy(seconds: $seconds, throwOnLateSuccess: $throwOnLateSuccess);

        return $this->withLayer(static fn (StateStore $store): Policy => $policy);
    }

    /**
     * @param callable(Throwable, Context): mixed $handler
     * @param list<class-string<Throwable>> $on
     */
    public function fallback(callable $handler, array $on = [Throwable::class]): self
    {
        return new self(
            name: $this->name,
            layers: $this->layers,
            fallbacks: [...$this->fallbacks, new FallbackPolicy($handler, $on)],
            store: $this->store,
            clock: $this->clock,
            randomizer: $this->randomizer,
            events: $this->events,
        );
    }

    public function store(StateStore $store): self
    {
        return new self(
            name: $this->name,
            layers: $this->layers,
            fallbacks: $this->fallbacks,
            store: $store,
            clock: $this->clock,
            randomizer: $this->randomizer,
            events: $this->events,
        );
    }

    public function clock(Clock $clock): self
    {
        return new self(
            name: $this->name,
            layers: $this->layers,
            fallbacks: $this->fallbacks,
            store: $this->store,
            clock: $clock,
            randomizer: $this->randomizer,
            events: $this->events,
        );
    }

    public function randomizer(Randomizer $randomizer): self
    {
        return new self(
            name: $this->name,
            layers: $this->layers,
            fallbacks: $this->fallbacks,
            store: $this->store,
            clock: $this->clock,
            randomizer: $randomizer,
            events: $this->events,
        );
    }

    public function events(EventDispatcherInterface $events): self
    {
        return new self(
            name: $this->name,
            layers: $this->layers,
            fallbacks: $this->fallbacks,
            store: $this->store,
            clock: $this->clock,
            randomizer: $this->randomizer,
            events: $events,
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    public function call(callable $fn): mixed
    {
        $store = $this->store ?? self::defaultStore();
        $policies = $this->fallbacks;

        foreach ($this->layers as $factory) {
            $policies[] = $factory($store);
        }

        $context = new Context(
            name: $this->name,
            clock: $this->clock ?? new SystemClock(),
            randomizer: $this->randomizer ?? new SystemRandomizer(),
            events: $this->events,
        );

        return (new Pipeline($policies))->execute($fn, $context);
    }

    /**
     * @internal Resets the process-wide default store, for tests.
     */
    public static function flushDefaultStore(): void
    {
        self::$defaultStore = null;
    }

    private static function defaultStore(): StateStore
    {
        return self::$defaultStore ??= new InMemoryStore();
    }

    /**
     * @param Closure(StateStore): Policy $factory
     */
    private function withLayer(Closure $factory): self
    {
        return new self(
            name: $this->name,
            layers: [...$this->layers, $factory],
            fallbacks: $this->fallbacks,
            store: $this->store,
            clock: $this->clock,
            randomizer: $this->randomizer,
            events: $this->events,
        );
    }
}
