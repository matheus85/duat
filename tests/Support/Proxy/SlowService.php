<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\Timeout;
use Duat\Tests\Support\FakeClock;

final class SlowService
{
    public function __construct(private readonly FakeClock $clock)
    {
    }

    #[Timeout(seconds: 5.0)]
    public function slowWork(): string
    {
        $this->clock->advance(6.0);

        return 'late';
    }
}
