<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

/**
 * @internal
 *
 * Mixed into every generated double, `override` mode included — identity
 * tracking has to survive independent of whether the seven control verbs
 * (`DoubleControlMethods`) do, since an `override`-generated double
 * deliberately omits those. Split out from `DoubleControlMethods` for
 * exactly that reason: two dunder-prefixed methods and a property, never
 * reserved-name-collision-checked (see `ClassGenerator::RESERVED_METHODS`),
 * so mixing this in is always safe regardless of what the target declares.
 */
trait DoubleIdentity
{
    /**
     * @internal
     *
     * An ordinary property, deliberately — PHP copies an object-typed
     * property by reference on `clone`, for free, with no `__clone` logic
     * needed. Double::create() sets this once via reflection (readonly
     * properties can't have a default value, and this must work whether or
     * not the generated class itself is marked readonly); Double::states()
     * is keyed by this identity instead of the double directly, so a clone
     * — which PHP creates with no way for us to reach the original it came
     * from — resolves to the exact same DoubleState its original has,
     * matching a Mockery mock's own clone behavior (expectations and call
     * history live as ordinary properties there too, so its clone shares
     * them the same way).
     */
    private readonly object $__td_identity;

    /** @internal */
    public function __td_identity(): object
    {
        return $this->__td_identity;
    }

    /** @internal */
    public static function __td_instantiate(): static
    {
        return (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
    }
}
