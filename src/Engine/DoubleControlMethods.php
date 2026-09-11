<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\Double;

/**
 * @internal
 *
 * Mixed into every generated double by ClassGenerator. These seven methods
 * (expects, allows, strict, passthru, received, unused, verify) are the
 * reserved control API — ClassGenerator's collision check runs before a
 * double using this trait is ever generated.
 */
trait DoubleControlMethods
{
    /** @internal */
    public static function __td_instantiate(): static
    {
        return (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
    }

    public function expects(string $method): MethodExpectation
    {
        return Double::registerExpectation($this, $method, required: true);
    }

    public function allows(string $method): MethodExpectation
    {
        return Double::registerExpectation($this, $method, required: false);
    }

    public function strict(): static
    {
        Double::stateFor($this)->setMode(Mode::Strict);

        return $this;
    }

    /**
     * $realInstance, if omitted, falls back to the real instance for()
     * remembered (DoubleState::knownInstance()). With neither, there's no
     * real instance at all to copy from — the double just keeps the
     * uninitialized state it already has (see
     * PassthruInitializer::assertConstructible()), real constructor never
     * run. Either way, an unmatched call afterward runs on the double
     * itself, via the real body ClassGenerator generated for it (see
     * ClassGenerator::buildRealMethod() and
     * ProxyBehavior::handleUnmatchedCall()), not on a separate wrapped
     * object. That's what lets a self-call made from inside that real body
     * re-enter the double and hit a configured stub.
     */
    public function passthru(?object $realInstance = null): static
    {
        $state = Double::stateFor($this);
        $realInstance ??= $state->knownInstance();

        if ($realInstance !== null) {
            PassthruInitializer::copyState($this, $realInstance, $state->target());
        } else {
            PassthruInitializer::assertConstructible($state->target());
        }

        $state->configurePassthru();

        return $this;
    }

    // received() and verify() both delegate to a same-named Double
    // static — this trait has no access to the private static
    // double->state map that implementation needs.
    public function received(string $method): ReceivedAssertion
    {
        return Double::received($this, $method);
    }

    /**
     * Asserts the double as a whole never received a single call, to any
     * method — unlike received($method), which checks one named method.
     */
    public function unused(): void
    {
        Double::unused($this);
    }

    public function verify(): void
    {
        Double::verify($this);
    }
}
