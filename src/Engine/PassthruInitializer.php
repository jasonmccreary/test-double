<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\Exceptions\PassthruAutoInstantiationException;

/**
 * @internal
 *
 * Backs ->passthru() (see DoubleControlMethods::passthru()): gets the
 * double's own property state to match what a real instance would have (or,
 * with no instance supplied, confirms there's at least a real class to run
 * methods on), before Mode::Passthru is ever configured on it. There's no
 * separate object to delegate to afterward — the double *is* the real
 * object from here on, which is what lets ProxyBehavior route an unmatched
 * call to the double's own "__td_real_*" body (see
 * ClassGenerator::buildRealMethod()) and have a self-call inside it
 * re-enter the double instead of escaping to a wrapped instance.
 */
final class PassthruInitializer
{
    /**
     * No existing instance was supplied — the double simply stays as it
     * already is (built via newInstanceWithoutConstructor() in
     * Double::create()), properties uninitialized, real constructor never
     * run. Deliberately not an attempt to construct $target for real: a
     * constructor needing arguments this has no way to supply would
     * otherwise make passthru() unusable for exactly the classes it's most
     * useful for — isolating one method's real logic from its siblings,
     * regardless of what the class's constructor needs. A real method that
     * goes on to touch a property the constructor would have set throws
     * PHP's own clear "must not be accessed before initialization" error at
     * that point, which is diagnosis enough on its own.
     *
     * An interface is the one case still rejected outright: it has no real
     * method bodies at all for ClassGenerator to have generated a
     * "__td_real_*" sibling for (see ClassGenerator::buildRealMethod()), so
     * an unmatched call would hit a method that doesn't exist rather than
     * failing clearly.
     */
    public static function assertConstructible(string $target): void
    {
        if ((new \ReflectionClass($target))->isInterface()) {
            throw PassthruAutoInstantiationException::isInterface($target);
        }
    }

    /**
     * An existing instance was supplied — copies its property values onto
     * $double via reflection instead of keeping it as a separate delegate.
     * ReflectionClass::getProperties() already walks the whole inheritance
     * chain and resolves private inherited properties to their correct
     * declaring scope, so a single pass over $realInstance's own reflection
     * is enough; no manual parent-class walk needed. Only initialized
     * properties are copied — an uninitialized typed property has nothing to
     * read, and $double is already in that same uninitialized state for
     * anything it doesn't receive here.
     */
    public static function copyState(object $double, object $realInstance): void
    {
        foreach ((new \ReflectionClass($realInstance))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($realInstance)) {
                continue;
            }

            $property->setValue($double, $property->getValue($realInstance));
        }
    }
}
