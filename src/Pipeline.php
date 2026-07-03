<?php

declare(strict_types=1);

namespace Duat;

use Duat\Contract\Policy;

/**
 * Composes policies around a callable, outermost first. Each policy decides
 * whether, when and how many times the next layer runs.
 */
final readonly class Pipeline
{
    /** @var list<Policy> */
    private array $policies;

    /**
     * @param list<Policy> $policies
     */
    public function __construct(array $policies)
    {
        $this->policies = $policies;
    }

    /**
     * @param callable(): mixed $fn
     */
    public function execute(callable $fn, Context $context): mixed
    {
        $chain = static fn (Context $context): mixed => $fn();

        foreach (array_reverse($this->policies) as $policy) {
            $chain = static fn (Context $context): mixed => $policy->execute($chain, $context);
        }

        return $chain($context);
    }
}
