<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\Retry;

final class StaticService
{
    #[Retry]
    public static function boom(): string
    {
        return 'static';
    }
}
