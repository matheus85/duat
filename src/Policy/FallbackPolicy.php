<?php

declare(strict_types=1);

namespace Duat\Policy;

use Closure;
use Duat\Context;
use Duat\Contract\Policy;
use Duat\Event\FallbackExecuted;
use Throwable;

/**
 * Meant to be the outermost pipeline layer, so it can catch failures from
 * every other policy. The fluent builder enforces that placement.
 */
final class FallbackPolicy implements Policy
{
    private readonly Closure $handler;

    /**
     * @param callable(Throwable, Context): mixed $handler
     * @param list<class-string<Throwable>> $on Exceptions that trigger the
     *        fallback; anything else propagates.
     */
    public function __construct(
        callable $handler,
        private readonly array $on = [Throwable::class],
    ) {
        $this->handler = $handler(...);
    }

    public function execute(callable $next, Context $context): mixed
    {
        try {
            return $next($context);
        } catch (Throwable $exception) {
            if (!$this->matches($exception)) {
                throw $exception;
            }

            $context->dispatch(new FallbackExecuted($context->name, $exception));

            return ($this->handler)($exception, $context);
        }
    }

    private function matches(Throwable $exception): bool
    {
        foreach ($this->on as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }
}
