<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\Exceptions\ModeConfigurationException;

/**
 * @internal
 *
 * Holds everything about one double: the target it was created for, its
 * display label, its mode, every expectation registered against it (in
 * registration order, since matching prefers the last-registered candidate
 * that both matches and still has remaining call capacity — see
 * ProxyBehavior::findMatch()), and every call actually observed, regardless
 * of whether it matched anything.
 */
final class DoubleState
{
    /** @var list<MethodExpectation> */
    private array $expectations = [];

    /** @var list<array{method: string, arguments: array}> */
    private array $calls = [];

    private ?Mode $mode = null;

    // A real instance supplied directly to Double::for($instance). Remembered
    // independent of mode, so a later ->passthru() with no argument can reuse
    // it instead of auto-instantiating a fresh one.
    private ?object $knownInstance = null;

    private int $fabricationDepth = 0;

    /**
     * The furthest slot reached so far by an ordered()-marked call — see
     * orderedExpectations(). 0 is a safe starting sentinel: the first ordered()-marked
     * expectation's own slot is always index 0, and comparing a slot against
     * itself never counts as a regression.
     */
    private int $orderCursor = 0;

    public function __construct(
        private readonly string $target,
        private readonly string $label,
    ) {}

    public function target(): string
    {
        return $this->target;
    }

    /**
     * Almost always a single-element list. An intersection-typed fabrication
     * stores its constituent interfaces joined with "&" in $target for
     * display — PHP names can never contain "&", so splitting on it is safe.
     *
     * @return list<string>
     */
    public function targetCandidates(): array
    {
        return explode('&', $this->target);
    }

    /**
     * The first target candidate (see targetCandidates()) that declares the
     * given method, or null if none does. Used by callers that need to
     * reflect a method (e.g. SafeDefaultResolver) or check it exists
     * (Double::registerExpectation).
     */
    public function declaringCandidate(string $method): ?string
    {
        foreach ($this->targetCandidates() as $candidate) {
            if (method_exists($candidate, $method)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether $method, as declared on $declaringCandidate, is static. Takes
     * $declaringCandidate as a parameter since callers already have it in
     * hand from declaringCandidate() before they'd need this.
     */
    public function isStatic(string $declaringCandidate, string $method): bool
    {
        return (new \ReflectionMethod($declaringCandidate, $method))->isStatic();
    }

    /**
     * The real declared parameter names for $method, for labeling argument
     * mismatches by name instead of position (see Double::verifyState()).
     * A variadic parameter's name appears once here even though it can
     * cover several actual arguments — expanding that is the caller's job,
     * since only the caller knows the actual argument count.
     *
     * Empty only if $method somehow isn't reflectable, which shouldn't
     * happen for a real registered expectation — registerExpectation()
     * already requires declaringCandidate() to resolve before allowing
     * expects()/allows() at all.
     *
     * @return list<string>
     */
    public function parameterNames(string $method): array
    {
        $declaringCandidate = $this->declaringCandidate($method);

        if ($declaringCandidate === null) {
            return [];
        }

        return array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod($declaringCandidate, $method))->getParameters(),
        );
    }

    /**
     * Every method name method_exists() would find on any target candidate,
     * for UnknownMethodException's "did you mean" suggestion.
     *
     * @return list<string>
     */
    public function declarableMethodNames(): array
    {
        $names = [];

        foreach ($this->targetCandidates() as $candidate) {
            // Reflection's own getMethods(), not get_class_methods() — the latter
            // only sees public methods from outside the declaring class, and this
            // should stay exactly as permissive as declaringCandidate() about visibility.
            foreach ((new \ReflectionClass($candidate))->getMethods() as $method) {
                $names[$method->getName()] = true;
            }
        }

        return array_keys($names);
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Loose is the architected default — reachable only implicitly, since
     * there's deliberately no ->loose() verb.
     */
    public function mode(): Mode
    {
        return $this->mode ?? Mode::Loose;
    }

    public function setMode(Mode $mode): void
    {
        if ($this->mode !== null) {
            throw new ModeConfigurationException($this->label, $this->mode->name, $mode->name, $this->isFabricated());
        }

        $this->mode = $mode;
    }

    /**
     * ->passthru() sets the mode. By the time this runs, the double's own
     * state already matches a real instance (see PassthruInitializer, called
     * from DoubleControlMethods before this) — there's no separate object to
     * remember here, since an unmatched call runs on the double itself (see
     * ProxyBehavior).
     */
    public function configurePassthru(): void
    {
        $this->setMode(Mode::Passthru);
    }

    /**
     * @internal used only by Double::create()
     */
    public function rememberRealInstance(object $instance): void
    {
        $this->knownInstance = $instance;
    }

    public function knownInstance(): ?object
    {
        return $this->knownInstance;
    }

    /**
     * @internal used only by Double::fabricate()/fabricateIntersection()
     */
    public function markFabricated(int $depth): void
    {
        $this->fabricationDepth = $depth;
    }

    public function fabricationDepth(): int
    {
        return $this->fabricationDepth;
    }

    public function isFabricated(): bool
    {
        return $this->fabricationDepth > 0;
    }

    public function registerExpectation(MethodExpectation $expectation): void
    {
        $this->expectations[] = $expectation;
    }

    /**
     * @return list<MethodExpectation> in registration order
     */
    public function expectationsFor(string $method): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn (MethodExpectation $expectation): bool => $expectation->method() === $method,
        ));
    }

    public function recordCall(string $method, array $arguments): void
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
    }

    /**
     * @return list<array>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['arguments'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }

    /**
     * Every call recorded on this double, across every method — the
     * unfiltered counterpart to callsFor(). Only Double::unused()
     * needs this: an assertion about the double as a whole, not any one
     * method, so it can't narrow by method name up front the way callsFor()
     * does.
     *
     * @return list<array{method: string, arguments: array}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<MethodExpectation>
     */
    public function unmetExpectations(): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn (MethodExpectation $expectation): bool => ! $expectation->isSatisfied(),
        ));
    }

    /**
     * Every ordered()-marked expectation registered on this double, in
     * registration order. An expectation's position in this list is its slot for call-order
     * enforcement (see ProxyBehavior); no separate slot-numbering
     * bookkeeping is needed since $expectations is already
     * registration-ordered.
     *
     * @return list<MethodExpectation>
     */
    public function orderedExpectations(): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn (MethodExpectation $expectation): bool => $expectation->isOrdered(),
        ));
    }

    public function orderCursor(): int
    {
        return $this->orderCursor;
    }

    public function advanceOrderCursor(int $slot): void
    {
        $this->orderCursor = $slot;
    }
}
