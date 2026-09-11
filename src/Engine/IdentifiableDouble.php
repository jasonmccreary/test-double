<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

/**
 * @internal
 *
 * The minimal, real, checkable fact `Double::stateFor()` needs: "this object
 * has a `Double`-tracked identity," independent of whether it also carries
 * the seven control verbs. Split out of `DoubleInterface` so a double
 * generated in `override` mode — which can't implement `DoubleInterface`
 * itself, since that's exactly the collision `override` exists to route
 * around — still has a real, checkable identity `stateFor()` can key off.
 */
interface IdentifiableDouble
{
    public function __td_identity(): object;
}
