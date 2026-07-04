<?php

declare(strict_types=1);

namespace Duat\Proxy;

use BadMethodCallException;
use Duat\Context;
use Duat\Contract\Clock;
use Duat\Contract\Randomizer;
use Duat\Pipeline;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Composition-based proxy returned by ProxyFactory::wrap(). Annotated
 * methods run through their resilience pipeline with the call arguments
 * exposed at Context::$metadata['arguments']; everything else is forwarded
 * untouched.
 *
 * Since interception happens in __call, the proxy is not an instanceof of
 * the wrapped class and static analysis loses the method signatures. The
 * README documents this trade-off.
 */
final class Proxy
{
    /**
     * @param array<string, array{pipeline: Pipeline, name: string}> $methods
     *        Keyed by lowercased method name; name is the canonical
     *        "Class::method" used for shared state and events.
     */
    public function __construct(
        private readonly object $instance,
        private readonly array $methods,
        private readonly Clock $clock,
        private readonly Randomizer $randomizer,
        private readonly ?EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $entry = $this->methods[strtolower($method)] ?? null;

        if ($entry === null) {
            if (!is_callable([$this->instance, $method])) {
                throw new BadMethodCallException(sprintf(
                    'Method %s::%s() does not exist or is not public.',
                    $this->instance::class,
                    $method,
                ));
            }

            return $this->instance->{$method}(...$arguments);
        }

        $context = new Context(
            name: $entry['name'],
            clock: $this->clock,
            randomizer: $this->randomizer,
            metadata: ['arguments' => $arguments],
            events: $this->events,
        );

        return $entry['pipeline']->execute(
            fn (): mixed => $this->instance->{$method}(...$arguments),
            $context,
        );
    }
}
