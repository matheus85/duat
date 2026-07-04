<?php

declare(strict_types=1);

namespace Duat\Exception;

final class RateLimitExceededException extends DuatException
{
    public function __construct(
        public readonly string $name,
        public readonly int $maxCalls,
        public readonly int $perSeconds,
        public readonly float $retryAfterSeconds,
    ) {
        parent::__construct(sprintf(
            'Rate limit for "%s" exceeded (%d per %ds), retry in %.3fs.',
            $name,
            $maxCalls,
            $perSeconds,
            $retryAfterSeconds,
        ));
    }
}
