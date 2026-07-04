<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\Bulkhead;
use Duat\Attributes\RateLimiter;

final class GuardedService
{
    #[Bulkhead(maxConcurrent: 1)]
    public function enter(): string
    {
        return 'in';
    }

    #[RateLimiter(maxCalls: 1, perSeconds: 60)]
    public function throttled(): string
    {
        return 'through';
    }
}
