<?php

declare(strict_types=1);

namespace Duat\Event;

enum RejectionReason: string
{
    case CircuitOpen = 'circuit_open';
}
