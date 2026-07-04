<?php

declare(strict_types=1);

namespace Duat\Attributes;

use Attribute;
use Throwable;

/**
 * Points to another public method on the same class. It receives the
 * original call arguments plus the exception as the last parameter.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Fallback
{
    /**
     * @param list<class-string<Throwable>> $on
     */
    public function __construct(
        public string $method,
        public array $on = [Throwable::class],
    ) {
    }
}
