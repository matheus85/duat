<?php

declare(strict_types=1);

use Duat\Attributes\CircuitBreaker;
use Duat\Attributes\Fallback;
use Duat\Attributes\Retry;
use Duat\Backoff\BackoffType;
use Duat\Event\CallRejected;
use Duat\Event\CircuitClosed;
use Duat\Event\CircuitHalfOpened;
use Duat\Event\CircuitOpened;
use Duat\Event\FallbackExecuted;
use Duat\Event\RetryAttempted;
use Duat\Proxy\ProxyFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install at the repository root first.\n");

    exit(1);
}

require $autoload;

final class PaymentGateway
{
    private int $attempts = 0;

    #[Retry(maxAttempts: 3, backoffMs: 200.0, backoff: BackoffType::Constant, jitter: false)]
    #[CircuitBreaker(failureRateThreshold: 0.5, minimumCalls: 4, windowSeconds: 10, cooldownSeconds: 3)]
    #[Fallback(method: 'queueForLater')]
    public function charge(string $order, float $amount): string
    {
        $this->attempts++;

        // The acquirer stays down for the first attempts, then recovers.
        if ($this->attempts <= 5) {
            throw new RuntimeException('acquirer timed out');
        }

        return sprintf('charged %s: %.2f BRL', $order, $amount);
    }

    public function queueForLater(string $order, float $amount, Throwable $exception): string
    {
        return sprintf('queued %s (%s)', $order, $exception::class);
    }
}

final class EventPrinter implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        $line = match (true) {
            $event instanceof RetryAttempted => sprintf('retrying in %dms (%s)', (int) $event->delayMs, $event->exception->getMessage()),
            $event instanceof CircuitOpened => 'circuit OPENED',
            $event instanceof CircuitHalfOpened => 'circuit HALF-OPEN, probing',
            $event instanceof CircuitClosed => 'circuit CLOSED',
            $event instanceof CallRejected => 'rejected fast, circuit is open',
            $event instanceof FallbackExecuted => 'fallback taking over',
            default => $event::class,
        };

        echo "      [event] {$line}\n";

        return $event;
    }
}

$factory = new ProxyFactory(events: new EventPrinter());
$gateway = $factory->wrap(new PaymentGateway());

echo "Charging through an annotated gateway. The acquirer recovers after a while.\n\n";

for ($i = 1; $i <= 20; $i++) {
    printf("#%02d  %s\n", $i, (string) $gateway->charge(sprintf('order-%02d', $i), 149.90));
    usleep(400_000);
}
