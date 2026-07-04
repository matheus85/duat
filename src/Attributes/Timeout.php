<?php

declare(strict_types=1);

namespace Duat\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Timeout
{
    public function __construct(
        public float $seconds,
        public bool $throwOnLateSuccess = false,
    ) {
    }
}
