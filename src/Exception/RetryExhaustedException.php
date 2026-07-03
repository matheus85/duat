<?php

declare(strict_types=1);

namespace Duat\Exception;

use Throwable;

final class RetryExhaustedException extends DuatException
{
    public function __construct(public readonly int $attempts, Throwable $previous)
    {
        parent::__construct(
            sprintf('Retry exhausted after %d %s.', $attempts, $attempts === 1 ? 'attempt' : 'attempts'),
            previous: $previous,
        );
    }
}
