<?php

declare(strict_types=1);

namespace JMac\Testing;

use JMac\Testing\Engine\MethodExpectation;
use JMac\Testing\Engine\ReceivedAssertion;

/**
 * What `Double::for($target, override: true)` returns when $target actually
 * has a reserved-name collision (e.g. `Illuminate\Contracts\Auth\Access\Gate`
 * declaring its own `allows()`). The double it wraps implements only
 * $target's real interface — no control verbs mixed in at all, since at
 * least one of those names is already spoken for by $target's own real
 * method. This class exists to carry the seven verbs instead, so call-site
 * ergonomics stay identical to any other double
 * (`$gate->expects('allows')->with('foo')->returns(true)`); every method
 * here just forwards to the same `Double::` internals
 * `Engine\DoubleControlMethods` itself delegates to.
 *
 * It deliberately does not implement $target's interface — that's the whole
 * point, there's nowhere left to put a same-named real method and a
 * same-named control verb on one object. Use `instance()` to get the real,
 * $target-shaped double back out for injection into the system under test
 * (e.g. `App::instance(Gate::class, $gate->instance())`).
 */
final class OverriddenDouble implements DoubleInterface
{
    public function __construct(private readonly object $double) {}

    /**
     * The real, $target-shaped double this wraps — pass this, not the
     * wrapper itself, wherever the system under test needs something
     * shaped like $target (a container binding, a constructor argument,
     * Facade::swap(), etc.).
     */
    public function instance(): object
    {
        return $this->double;
    }

    public function __td_identity(): object
    {
        return $this->double->__td_identity();
    }

    public function expects(string $method): MethodExpectation
    {
        return Double::registerExpectation($this->double, $method, required: true);
    }

    public function allows(string $method): MethodExpectation
    {
        return Double::registerExpectation($this->double, $method, required: false);
    }

    public function strict(): static
    {
        Double::strict($this->double);

        return $this;
    }

    public function passthru(?object $realInstance = null): static
    {
        Double::passthru($this->double, $realInstance);

        return $this;
    }

    public function received(string $method): ReceivedAssertion
    {
        return Double::received($this->double, $method);
    }

    public function unused(): void
    {
        Double::unused($this->double);
    }

    public function verify(): void
    {
        Double::verify($this->double);
    }
}
