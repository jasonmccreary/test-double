<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * calculate() calls double() on $this internally — used to prove whether a
 * stub configured for double() is actually reachable from inside another
 * unstubbed method on the same double, which regular ->passthru() can't do
 * (see PassthruModeTest) and inline passthru is meant to fix.
 */
class SelfCallingCalculator
{
    public function calculate(int $value): int
    {
        return $this->double($value) + 1;
    }

    public function double(int $value): int
    {
        return $value * 2;
    }
}
