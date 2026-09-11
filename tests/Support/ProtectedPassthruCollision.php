<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * A concrete class (not an interface, since interfaces can't declare
 * non-public methods) with a protected reserved-name method — the same
 * shape as Illuminate\Support\LazyCollection's own protected passthru().
 * Widening this to public to satisfy DoubleInterface is what PHP rejects
 * as an uncatchable fatal if ClassGenerator doesn't catch it first.
 */
class ProtectedPassthruCollision
{
    protected function passthru(): bool
    {
        return true;
    }
}
