<?php

declare(strict_types=1);

namespace Duat\Exception;

final class TimeoutExceededException extends DuatException
{
    public function __construct(
        public readonly string $name,
        public readonly float $timeoutSeconds,
        public readonly float $elapsedSeconds,
    ) {
        parent::__construct(sprintf(
            'Operation "%s" exceeded its %.3fs timeout after %.3fs.',
            $name,
            $timeoutSeconds,
            $elapsedSeconds,
        ));
    }
}
