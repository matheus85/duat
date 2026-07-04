<?php

declare(strict_types=1);

namespace Duat\Tests\Support\Proxy;

use Duat\Attributes\Fallback;

final class BrokenFallbackService
{
    #[Fallback(method: 'nowhere')]
    public function act(): string
    {
        return 'acted';
    }
}
