<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\Bulkhead;

final class GuardedService
{
    #[Bulkhead(maxConcurrent: 1)]
    public function enter(): string
    {
        return 'in';
    }
}
