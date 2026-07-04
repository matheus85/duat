<?php

declare(strict_types=1);

namespace Duat\Exception;

final class BulkheadFullException extends DuatException
{
    public function __construct(
        public readonly string $name,
        public readonly int $maxConcurrent,
    ) {
        parent::__construct(sprintf(
            'Bulkhead for "%s" is full (%d concurrent %s).',
            $name,
            $maxConcurrent,
            $maxConcurrent === 1 ? 'call' : 'calls',
        ));
    }
}
