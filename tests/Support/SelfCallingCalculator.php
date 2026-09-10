<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * calculate() calls double() on $this internally — used to prove whether a
 * stub configured for double() is actually reachable from inside another
 * unstubbed method on the same double, which regular ->passthru() can't do
 * (see PassthruModeTest) and passthru's real-body mechanism is meant to fix.
 *
 * double() is deliberately protected, not public: ClassGenerator's generated
 * "__td_real_*" sibling for it is called from ProxyBehavior, a class outside
 * the double entirely — a protected method inaccessible from that scope
 * falls back to the target's own __call() (if it has one) instead of a
 * visibility error, which silently breaks passthru rather than failing
 * loudly. A public-only double() would never have caught that.
 */
class SelfCallingCalculator
{
    public function calculate(int $value): int
    {
        return $this->double($value) + 1;
    }

    protected function double(int $value): int
    {
        return $value * 2;
    }
}
