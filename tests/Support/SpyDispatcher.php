<?php

declare(strict_types=1);

namespace Duat\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class SpyDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }
}
