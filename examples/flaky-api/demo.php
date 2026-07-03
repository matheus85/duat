<?php

declare(strict_types=1);

use Duat\Backoff\Backoff;
use Duat\Duat;
use Duat\Event\CallRejected;
use Duat\Event\CircuitClosed;
use Duat\Event\CircuitHalfOpened;
use Duat\Event\CircuitOpened;
use Duat\Event\DeadlineExceeded;
use Duat\Event\FallbackExecuted;
use Duat\Event\RetryAttempted;
use Psr\EventDispatcher\EventDispatcherInterface;

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install at the repository root first.\n");

    exit(1);
}

require $autoload;

final class ConsoleDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        $line = match (true) {
            $event instanceof RetryAttempted => sprintf(
                'retrying in %dms after attempt %d (%s)',
                (int) $event->delayMs,
                $event->attempt,
                $event->exception->getMessage(),
            ),
            $event instanceof CircuitOpened => $event->failureRate === null
                ? 'circuit OPENED by a failed probe'
                : sprintf('circuit OPENED at %.0f%% failures', $event->failureRate * 100),
            $event instanceof CircuitHalfOpened => 'circuit HALF-OPEN, probing',
            $event instanceof CircuitClosed => 'circuit CLOSED, back to normal',
            $event instanceof CallRejected => 'rejected fast, circuit is open',
            $event instanceof DeadlineExceeded => sprintf('late success after %.2fs', $event->elapsedSeconds),
            $event instanceof FallbackExecuted => 'fallback taking over',
            default => $event::class,
        };

        echo "      [event] {$line}\n";

        return $event;
    }
}

function fetchStatus(): string
{
    $context = stream_context_create([
        'http' => ['timeout' => 1.5, 'ignore_errors' => true],
    ]);

    $body = @file_get_contents('http://127.0.0.1:8080/', false, $context);

    if ($body === false) {
        throw new RuntimeException('timed out or unreachable (is docker compose up?)');
    }

    $status = (int) substr($http_response_header[0] ?? 'HTTP/1.1 000', 9, 3);

    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status);
    }

    return trim($body);
}

$rounds = (int) (getenv('DEMO_ROUNDS') ?: 60);

$flaky = Duat::for('flaky-api')
    ->events(new ConsoleDispatcher())
    ->retry(maxAttempts: 2, backoff: Backoff::constant(150.0))
    ->circuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, windowSeconds: 10, cooldownSeconds: 5)
    ->timeout(seconds: 1.5)
    ->fallback(static fn (): string => '{"status":"degraded","from":"fallback"}');

echo "Crossing the Duat: {$rounds} calls against a flaky API. Watch the circuit.\n\n";

for ($i = 1; $i <= $rounds; $i++) {
    printf("#%02d  %s\n", $i, (string) $flaky->call(fetchStatus(...)));
    usleep(250_000);
}
