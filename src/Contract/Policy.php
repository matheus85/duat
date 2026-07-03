<?php

declare(strict_types=1);

namespace Duat\Contract;

use Duat\Context;

interface Policy
{
    /**
     * @param callable(Context): mixed $next Next pipeline layer. Policies may
     *        hand it a derived context so attempt and deadline flow inward.
     */
    public function execute(callable $next, Context $context): mixed;
}
