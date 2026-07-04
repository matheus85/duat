<?php

declare(strict_types=1);

namespace Duat\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Bulkhead
{
    public function __construct(
        public int $maxConcurrent,
        public int $leaseSeconds = 60,
    ) {
    }
}
