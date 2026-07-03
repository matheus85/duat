<?php

declare(strict_types=1);

namespace Duat\Contract;

use Duat\Context;

interface Policy
{
    /**
     * @param callable(): mixed $fn
     */
    public function execute(callable $fn, Context $context): mixed;
}
