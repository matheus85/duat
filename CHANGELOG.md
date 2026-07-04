# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-07-04

### Added

- `BulkheadPolicy` capping concurrent calls per resource, with a safety
  lease that heals slots leaked by dead processes, plus `->bulkhead()` on
  the fluent builder.
- PHP 8 attributes (`#[Retry]`, `#[CircuitBreaker]`, `#[Timeout]`,
  `#[Bulkhead]`, `#[Fallback]`) applied through `ProxyFactory::wrap()`.
  Attribute order defines the pipeline and fallback methods receive the
  original call arguments plus the exception.
- `BackoffType` enum for naming backoff strategies where instances cannot
  be expressed, like attribute arguments.
- `examples/attributes`: annotated payment gateway demo, no docker needed.

### Changed

- Package description no longer lists features that have not shipped.

## [0.1.0] - 2026-07-03

First usable release: the whole synchronous resilience core.

### Added

- `RetryPolicy` with configurable attempts, retryable and abort exception
  lists, an `onRetry` hook and deadline awareness: it gives up when the next
  wait would not fit the remaining budget.
- Backoff strategies: constant, linear and exponential with cap and full
  jitter.
- `CircuitBreakerPolicy` with a time-based sliding window of per-second
  buckets, CLOSED/OPEN/HALF_OPEN transitions, cooldown, configurable
  half-open probes and per-resource isolation.
- `TimeoutPolicy` with honest deadline semantics: registers the deadline in
  the context, reports late successes through an event or an exception, and
  never pretends to interrupt blocking code.
- `FallbackPolicy` with exception filtering, always the outermost layer.
- `Pipeline` composing policies outside-in around an immutable `Context`.
- Events for any optional PSR-14 dispatcher: `RetryAttempted`,
  `CircuitOpened`, `CircuitHalfOpened`, `CircuitClosed`, `CallRejected`,
  `DeadlineExceeded` and `FallbackExecuted`.
- State stores sharing one behavioral contract: `InMemoryStore`,
  `Psr16Store` (non-atomic caveats documented) and `RedisStore` (ext-redis
  or Predis, atomic Lua increments and SET NX leases).
- Fluent entry point `Duat::for()` with immutable builders.
- `examples/flaky-api`: dockerized flaky API demonstrating every policy
  live.

[0.2.0]: https://github.com/matheus85/duat/releases/tag/v0.2.0
[0.1.0]: https://github.com/matheus85/duat/releases/tag/v0.1.0
