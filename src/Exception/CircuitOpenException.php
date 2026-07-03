<?php

declare(strict_types=1);

namespace Duat\Exception;

final class CircuitOpenException extends DuatException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(sprintf('Circuit for "%s" is open.', $name));
    }
}
