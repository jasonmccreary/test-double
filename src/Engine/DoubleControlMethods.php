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
    use DoubleIdentity;

    public function expects(string $method): MethodExpectation
    {
        return Double::registerExpectation($this, $method, required: true);
    }

    public function allows(string $method): MethodExpectation
    {
        return Double::registerExpectation($this, $method, required: false);
    }

    // strict(), passthru(), received() and verify() all delegate to a
    // same-named Double static — this trait has no access to the private
    // static double->state map those implementations need. Delegating
    // (rather than inlining) is also what lets OverriddenDouble reuse the
    // exact same logic against the double it wraps, instead of duplicating
    // it.
    public function strict(): static
    {
        Double::strict($this);

        return $this;
    }

    public function passthru(?object $realInstance = null): static
    {
        Double::passthru($this, $realInstance);

        return $this;
    }

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
