# flaky-api

A tiny API that alternates 15 seconds of health with 15 seconds of failures,
and a demo script that survives it with retry, circuit breaker, timeout and
fallback, printing every resilience event along the way.

## Run it

From the repository root:

```bash
composer install
cd examples/flaky-api
docker compose up -d
php demo.php
```

## What to watch

While the API is healthy you see normal responses, the occasional retry and
a late success now and then. When the failure phase starts, failures pile up
in the sliding window until the circuit opens. From there calls are rejected
instantly and the fallback answers. Every 5 seconds the circuit lets one
probe through: if the API is still down it reopens, and once the healthy
phase returns the probe succeeds and the circuit closes.

Stop the API mid-run (`docker compose stop`) to see the timeout path kick in.

Cleanup: `docker compose down`.
