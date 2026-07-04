<?php

declare(strict_types=1);

use Duat\Backoff\Backoff;
use Duat\Duat;
use Duat\Proxy\ProxyFactory;
use Duat\Store\InMemoryStore;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");

    exit(1);
}

require $autoload;

const ITERATIONS = 100_000;

/**
 * @param callable(): mixed $fn
 */
function measure(callable $fn): float
{
    for ($i = 0; $i < 1_000; $i++) {
        $fn();
    }

    $start = hrtime(true);

    for ($i = 0; $i < ITERATIONS; $i++) {
        $fn();
    }

    return (hrtime(true) - $start) / ITERATIONS / 1_000;
}

final class BenchTarget
{
    #[\Duat\Attributes\Retry(maxAttempts: 3, backoffMs: 100.0)]
    #[\Duat\Attributes\CircuitBreaker]
    public function work(): int
    {
        return 42;
    }
}

$callable = static fn (): int => 42;

$retryOnly = Duat::for('bench-retry')
    ->retry(maxAttempts: 3, backoff: Backoff::constant(100.0));

$fullChain = Duat::for('bench-full')
    ->retry(maxAttempts: 3, backoff: Backoff::constant(100.0))
    ->circuitBreaker()
    ->timeout(seconds: 5.0)
    ->fallback(static fn (): int => 0)
    ->store(new InMemoryStore());

$proxy = (new ProxyFactory())->wrap(new BenchTarget());

$scenarios = [
    'bare closure' => $callable,
    'retry only' => static fn (): mixed => $retryOnly->call($callable),
    'full chain' => static fn (): mixed => $fullChain->call($callable),
    'attribute proxy' => static fn (): mixed => $proxy->work(),
];

printf("Happy path, %s iterations each, PHP %s%s\n\n", number_format(ITERATIONS), PHP_VERSION, PHP_OS_FAMILY === 'Windows' ? ' on Windows' : '');

$baseline = null;

foreach ($scenarios as $label => $fn) {
    $microseconds = measure($fn);
    $baseline ??= $microseconds;

    printf("%-16s %8.2f us/call   %s\n", $label, $microseconds, $label === 'bare closure' ? 'baseline' : sprintf('+%.2f us', $microseconds - $baseline));
}
