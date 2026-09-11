<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * retryOnClone() clones $this internally before calling another method on
 * the clone — the same shape as Illuminate\Database\Eloquent\Relations\
 * HasOneOrMany::firstOrCreate(), which clones the query builder before
 * retrying a query on the copy. Used to prove a passthru double's real,
 * unstubbed method can clone itself and keep working against the clone,
 * rather than the clone immediately throwing on its next intercepted call.
 */
class ClonesItselfDuringCall
{
    public function retryOnClone(int $value): int
    {
        return (clone $this)->double($value);
    }

    public function double(int $value): int
    {
        return $value * 2;
    }
}
