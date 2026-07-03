<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use Closure;
use Duat\Context;
use Duat\Contract\Policy;

/**
 * Policy whose behavior is given by a closure, for composing arbitrary
 * pipeline scenarios in tests.
 */
final class CallbackPolicy implements Policy
{
    /**
     * @param Closure(callable(Context): mixed, Context): mixed $callback
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    public function execute(callable $next, Context $context): mixed
    {
        return ($this->callback)($next, $context);
    }
}
