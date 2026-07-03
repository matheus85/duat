# Duat

[![CI](https://github.com/matheus85/duat/actions/workflows/ci.yml/badge.svg)](https://github.com/matheus85/duat/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/matheus85/duat)](https://packagist.org/packages/matheus85/duat)
![PHP](https://img.shields.io/badge/php-8.3%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)

Unified resilience patterns for modern PHP: retry, circuit breaker, timeout
and fallback behind one fluent API. Zero required dependencies, no framework
coupling, no HTTP client coupling. Duat wraps callables, nothing else.

```php
use Duat\Duat;

$user = Duat::for('github')
    ->retry(maxAttempts: 3)
    ->fallback(fn () => ['login' => 'octocat', 'source' => 'cache'])
    ->call(fn () => json_decode(file_get_contents('https://api.github.com/users/octocat'), true));
```

In Egyptian mythology the Duat is the underworld the sun crosses every
night, fighting through the dark to be reborn at dawn. Failure, crossing,
recovery, in an endless cycle. That is the exact life of a circuit breaker
(closed, open, half-open, closed again), so the name stuck.

## Why another library?

PHP never got its Resilience4j. Java has it, .NET has Polly, Node has
cockatiel. Here the landscape is fragmented: Ganesha does circuit breaking
well, PrestaShop's circuit breaker is tied to specific HTTP clients, and
retry logic usually ends up as a hand-rolled loop with `sleep()` calls
spread across the codebase. I wanted the whole toolbox in one place,
framework agnostic and fully testable without touching a real clock, so I
built it.

|                       | Duat          | Ganesha | PrestaShop/circuit-breaker |
| --------------------- | ------------- | ------- | -------------------------- |
| Retry with backoff    | yes           | no      | no                         |
| Circuit breaker       | yes           | yes     | yes                        |
| Timeout (deadline)    | yes           | no      | per HTTP client            |
| Fallback              | yes           | no      | yes                        |
| Bulkhead              | planned (0.2) | no      | no                         |
| Rate limiter          | planned       | no      | no                         |
| PHP 8 attributes      | planned (0.2) | no      | no                         |
| Required dependencies | none          | none    | HTTP client                |

If you come from Java think Resilience4j, if you come from .NET think
Polly. That feature set is the goal, in PHP, one policy at a time.

## Install

```bash
composer require matheus85/duat
```

PHP 8.3 or newer, nothing else required. Optional: any PSR-16 cache or
Redis for shared state, any PSR-14 dispatcher for events.

## The pipeline

Policies compose like middleware around your callable. Method order defines
the nesting, outermost first, and `fallback()` always sits at the very
outside no matter where you declare it:

```php
use Duat\Backoff\Backoff;
use Duat\Duat;
use Duat\Store\RedisStore;

$result = Duat::for('sefaz-nfe')
    ->retry(maxAttempts: 3, backoff: Backoff::exponential(baseMs: 200, capMs: 10_000))
    ->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 10)
    ->timeout(seconds: 5.0)
    ->fallback(fn (Throwable $e) => ReceiptStatus::unavailable())
    ->store(new RedisStore($redis))
    ->call(fn () => $sefaz->queryReceipt($key));
```

Builders are immutable: every method returns a new instance, so you can
configure a chain once, inject it anywhere and reuse it freely.

### Order matters

`retry()->circuitBreaker()` retries around the breaker. When the circuit
opens, the rejection stops the retry loop immediately: Duat never retries
`CircuitOpenException`, because hammering an open circuit defeats its
purpose. `circuitBreaker()->retry()` puts the whole retry burst inside a
single breaker call instead, so one exhausted retry counts as one failure
in the window. Both arrangements are legitimate, pick one consciously.

## Policies

### Retry

```php
->retry(
    maxAttempts: 4,
    backoff: Backoff::exponential(baseMs: 200, capMs: 10_000, jitter: true),
    retryOn: [TransportException::class],
    abortOn: [AuthException::class],
    onRetry: fn (Throwable $e, Context $ctx) => $log->warning("attempt {$ctx->attempt} failed"),
)
```

Backoffs: `Backoff::constant()`, `Backoff::linear()` and
`Backoff::exponential()` with cap and full jitter (the AWS flavor: the
delay is drawn uniformly from zero to the doubling base). The default is
exponential, 200ms base, 10s cap, jitter on.

`abortOn` wins over `retryOn`. Exhaustion throws `RetryExhaustedException`
carrying the last failure as `previous`. And when composed with
`timeout()`, retry gives up as soon as the next wait would not fit the
remaining budget, rethrowing the real failure instead of sleeping past the
deadline.

### Circuit breaker

```php
->circuitBreaker(
    failureRateThreshold: 0.5, // opens at 50% failures...
    minimumCalls: 10,          // ...once the window holds 10 calls
    windowSeconds: 60,         // time-based sliding window
    cooldownSeconds: 30,       // time in OPEN before probing
    halfOpenMaxCalls: 1,       // probes allowed in HALF_OPEN
    recordOn: [Throwable::class],
)
```

The window is a set of per-second buckets in the state store, updated with
atomic increments, so multiple PHP-FPM workers share one view of the
resource. Each resource name gets its own circuit. Transitions emit events
(see below) and rejections throw `CircuitOpenException`.

### Timeout, honestly

```php
->timeout(seconds: 5.0)                           // report late successes
->timeout(seconds: 5.0, throwOnLateSuccess: true) // punish them
```

**What `timeout()` does not do**: synchronous PHP cannot interrupt a
blocking call, and Duat refuses to pretend otherwise (no `pcntl` tricks).
The policy registers a deadline in the context, inner layers read it
through `Context::remainingBudget()`, and when the callable comes back late
you either get the result plus a `DeadlineExceeded` event (default) or a
`TimeoutExceededException` (strict mode).

Real cancellation belongs in the client. Set your cURL, Guzzle or stream
timeouts, and feed them from the context if you want a single budget across
retries.

### Fallback

```php
->fallback(
    fn (Throwable $e, Context $ctx) => Status::degraded(),
    on: [CircuitOpenException::class, RetryExhaustedException::class],
)
```

Always the outermost layer, so it catches failures from every policy and
from the callable itself. `on` filters which exceptions trigger it; the
rest propagate untouched.

## Shared state

Circuit breaker state has to live somewhere. Pick a store:

| Store           | Backend                        | Atomicity                                     |
| --------------- | ------------------------------ | --------------------------------------------- |
| `InMemoryStore` | process array                  | single process only, default                  |
| `Psr16Store`    | any PSR-16 cache               | read-modify-write, benign races documented    |
| `RedisStore`    | ext-redis or Predis            | atomic (Lua increments, SET NX leases)        |

The default `InMemoryStore` does not cross PHP-FPM workers: each worker
would run its own circuit. Fine for CLI tools, queue workers and tests, but
production web workloads want `->store(new RedisStore($client))`.

## Events

Pass any PSR-14 dispatcher with `->events($dispatcher)` and Duat emits
readonly event objects: `RetryAttempted`, `CircuitOpened`,
`CircuitHalfOpened`, `CircuitClosed`, `CallRejected`, `DeadlineExceeded`
and `FallbackExecuted`. No dispatcher, no events, no overhead.

## Watch it live

`examples/flaky-api` ships a dockerized API that alternates health and
failure every 15 seconds, plus a demo script that crosses it with the full
pipeline while printing every event:

```bash
cd examples/flaky-api
docker compose up -d
php demo.php
```

You will see retries, the circuit opening at the failure threshold, fast
rejections with fallback answers, probes during cooldown and the circuit
closing once the API recovers.

## Testing your own code

Time and randomness are injectable everywhere. Implement the two tiny
interfaces `Duat\Contract\Clock` and `Duat\Contract\Randomizer`, pass them
with `->clock()` and `->randomizer()`, and your resilience tests run in
milliseconds with zero real sleeps. Duat's own suite (190+ tests) works
exactly like that.

## Roadmap

- **0.2**: PHP 8 attributes (`#[Retry]`, `#[CircuitBreaker]`...) via proxy
  factory, plus the bulkhead policy.
- **0.3**: first-class Laravel bridge as a separate package
  (`matheus85/duat-laravel`): service provider, cache-backed stores, HTTP
  client macro, native events.
- **Later**: rate limiter, mutation testing, benchmarks.

## License

MIT.
