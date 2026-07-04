<?php

declare(strict_types=1);

namespace Duat\Proxy;

use Duat\Attributes\Bulkhead;
use Duat\Attributes\CircuitBreaker;
use Duat\Attributes\Fallback;
use Duat\Attributes\Retry;
use Duat\Attributes\Timeout;
use Duat\Context;
use Duat\Contract\Clock;
use Duat\Contract\Randomizer;
use Duat\Contract\StateStore;
use Duat\Pipeline;
use Duat\Policy\BulkheadPolicy;
use Duat\Policy\CircuitBreakerPolicy;
use Duat\Policy\FallbackPolicy;
use Duat\Policy\RetryPolicy;
use Duat\Policy\TimeoutPolicy;
use Duat\Store\InMemoryStore;
use Duat\Support\SystemClock;
use Duat\Support\SystemRandomizer;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads resilience attributes from an object and wraps it in a proxy that
 * applies the matching pipeline to each annotated method. Attribute order
 * defines the pipeline order, outermost first, and Fallback is always the
 * outermost layer, mirroring the fluent builder.
 *
 * Keep one factory per process (or per container) so every proxy shares
 * the same state store; circuit breakers are keyed by "Class::method".
 */
final class ProxyFactory
{
    private readonly StateStore $store;

    private readonly Clock $clock;

    private readonly Randomizer $randomizer;

    public function __construct(
        ?StateStore $store = null,
        ?Clock $clock = null,
        ?Randomizer $randomizer = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->store = $store ?? new InMemoryStore();
        $this->clock = $clock ?? new SystemClock();
        $this->randomizer = $randomizer ?? new SystemRandomizer();
    }

    public function wrap(object $instance): Proxy
    {
        $reflection = new ReflectionClass($instance);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $pipeline = $this->pipelineFor($instance, $method);

            if ($pipeline === null) {
                continue;
            }

            if ($method->isStatic()) {
                throw new InvalidArgumentException(sprintf(
                    'Resilience attributes on static methods are not supported (%s::%s).',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            $methods[strtolower($method->getName())] = [
                'pipeline' => $pipeline,
                'name' => $reflection->getName() . '::' . $method->getName(),
            ];
        }

        if ($methods === []) {
            throw new InvalidArgumentException(sprintf(
                'No resilience attributes found on %s, wrapping it would do nothing.',
                $reflection->getName(),
            ));
        }

        return new Proxy($instance, $methods, $this->clock, $this->randomizer, $this->events);
    }

    private function pipelineFor(object $instance, ReflectionMethod $method): ?Pipeline
    {
        $policies = [];
        $fallbacks = [];

        foreach ($method->getAttributes() as $attribute) {
            $config = match ($attribute->getName()) {
                Retry::class,
                CircuitBreaker::class,
                Timeout::class,
                Bulkhead::class,
                Fallback::class => $attribute->newInstance(),
                default => null,
            };

            if ($config === null) {
                continue;
            }

            if ($config instanceof Retry) {
                $policies[] = new RetryPolicy(
                    maxAttempts: $config->maxAttempts,
                    backoff: $config->backoff->build($config->backoffMs, $config->capMs, $config->jitter),
                    retryOn: $config->retryOn,
                    abortOn: $config->abortOn,
                );
            } elseif ($config instanceof CircuitBreaker) {
                $policies[] = new CircuitBreakerPolicy(
                    store: $this->store,
                    failureRateThreshold: $config->failureRateThreshold,
                    minimumCalls: $config->minimumCalls,
                    windowSeconds: $config->windowSeconds,
                    cooldownSeconds: $config->cooldownSeconds,
                    halfOpenMaxCalls: $config->halfOpenMaxCalls,
                    recordOn: $config->recordOn,
                );
            } elseif ($config instanceof Timeout) {
                $policies[] = new TimeoutPolicy(
                    seconds: $config->seconds,
                    throwOnLateSuccess: $config->throwOnLateSuccess,
                );
            } elseif ($config instanceof Bulkhead) {
                $policies[] = new BulkheadPolicy(
                    store: $this->store,
                    maxConcurrent: $config->maxConcurrent,
                    leaseSeconds: $config->leaseSeconds,
                );
            } elseif ($config instanceof Fallback) {
                $fallbacks[] = $this->fallbackPolicy($instance, $method, $config);
            }
        }

        if ($policies === [] && $fallbacks === []) {
            return null;
        }

        return new Pipeline([...$fallbacks, ...$policies]);
    }

    private function fallbackPolicy(object $instance, ReflectionMethod $method, Fallback $config): FallbackPolicy
    {
        $target = $config->method;

        if (!method_exists($instance, $target) || !(new ReflectionMethod($instance, $target))->isPublic()) {
            throw new InvalidArgumentException(sprintf(
                'Fallback method %s::%s() pointed to by %s() does not exist or is not public.',
                $instance::class,
                $target,
                $method->getName(),
            ));
        }

        return new FallbackPolicy(
            static function (Throwable $exception, Context $context) use ($instance, $target): mixed {
                $arguments = $context->metadata['arguments'] ?? [];
                $arguments = is_array($arguments) ? array_values($arguments) : [];

                return $instance->{$target}(...[...$arguments, $exception]);
            },
            on: $config->on,
        );
    }
}
