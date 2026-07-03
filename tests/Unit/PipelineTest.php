<?php

declare(strict_types=1);

namespace Duat\Tests\Unit;

use Duat\Context;
use Duat\Pipeline;
use Duat\Tests\Support\CallbackPolicy;
use Duat\Tests\Support\FakeClock;
use Duat\Tests\Support\FakeRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pipeline::class)]
final class PipelineTest extends TestCase
{
    private function context(): Context
    {
        return new Context(name: 'api', clock: new FakeClock(), randomizer: new FakeRandomizer());
    }

    public function testExecutesTheCallableDirectlyWhenEmpty(): void
    {
        $pipeline = new Pipeline([]);

        self::assertSame('ok', $pipeline->execute(static fn (): string => 'ok', $this->context()));
    }

    public function testPoliciesWrapOutsideIn(): void
    {
        $order = [];
        $outer = new CallbackPolicy(function (callable $next, Context $context) use (&$order): mixed {
            $order[] = 'outer';

            return $next($context);
        });
        $inner = new CallbackPolicy(function (callable $next, Context $context) use (&$order): mixed {
            $order[] = 'inner';

            return $next($context);
        });

        $pipeline = new Pipeline([$outer, $inner]);
        $result = $pipeline->execute(function () use (&$order): string {
            $order[] = 'fn';

            return 'ok';
        }, $this->context());

        self::assertSame('ok', $result);
        self::assertSame(['outer', 'inner', 'fn'], $order);
    }

    public function testDerivedContextFlowsInward(): void
    {
        $seen = [];
        $outer = new CallbackPolicy(
            static fn (callable $next, Context $context): mixed => $next($context->withAttempt(7)),
        );
        $inner = new CallbackPolicy(function (callable $next, Context $context) use (&$seen): mixed {
            $seen[] = $context->attempt;

            return $next($context);
        });

        $pipeline = new Pipeline([$outer, $inner]);
        $pipeline->execute(static fn (): ?string => null, $this->context());

        self::assertSame([7], $seen);
    }

    public function testPolicyCanShortCircuit(): void
    {
        $called = false;
        $blocker = new CallbackPolicy(static fn (callable $next, Context $context): string => 'blocked');

        $pipeline = new Pipeline([$blocker]);
        $result = $pipeline->execute(function () use (&$called): string {
            $called = true;

            return 'ok';
        }, $this->context());

        self::assertSame('blocked', $result);
        self::assertFalse($called);
    }

    public function testPolicyCanRerunInnerLayers(): void
    {
        $calls = 0;
        $twice = new CallbackPolicy(static function (callable $next, Context $context): mixed {
            $next($context);

            return $next($context);
        });

        $pipeline = new Pipeline([$twice]);
        $pipeline->execute(function () use (&$calls): int {
            return ++$calls;
        }, $this->context());

        self::assertSame(2, $calls);
    }
}
