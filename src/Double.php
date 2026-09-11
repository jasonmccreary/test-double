<?php

declare(strict_types=1);

namespace JMac\Testing;

use JMac\Testing\Diagnostics\ArgumentFormatter;
use JMac\Testing\Diagnostics\DidYouMean;
use JMac\Testing\Diagnostics\UnsatisfiedExpectation;
use JMac\Testing\Engine\ArgumentLabeler;
use JMac\Testing\Engine\ClassGenerator;
use JMac\Testing\Engine\DoubleState;
use JMac\Testing\Engine\ExceptionFactory;
use JMac\Testing\Engine\FinalBypass;
use JMac\Testing\Engine\IdentifiableDouble;
use JMac\Testing\Engine\MethodExpectation;
use JMac\Testing\Engine\Mode;
use JMac\Testing\Engine\PassthruInitializer;
use JMac\Testing\Engine\PhpUnitIntegration;
use JMac\Testing\Engine\ReceivedAssertion;
use JMac\Testing\Exceptions\MagicMethodException;
use JMac\Testing\Exceptions\StaticMethodException;
use JMac\Testing\Exceptions\UnknownMethodException;

/**
 * The public facade and the library's sole entry point. `Double::for()`
 * creates a double; `$double->verify()` is the manual verification call
 * every test runner can use. PHPUnit users can skip it via
 * `Integrations\PHPUnit\VerifiesDoubles`, which auto-verifies every double
 * created during a test. A non-PHPUnit runner wanting the same "enable
 * before, verify after" flow can drive it directly via `enableAutoVerify()` /
 * `verifyAll()` — the latter's return value doubles as a framework-agnostic
 * "a check passed" signal — and `pauseAutoVerify()` /
 * `resumeAutoVerify()` for the concurrent case (see `AutoVerifySnapshot`).
 * `listen()` is the live counterpart: a registered listener is notified with
 * a `CheckEvent` as each check resolves, pass or fail, instead of only
 * learning the aggregate outcome after the fact.
 */
final class Double
{
    private static ?\WeakMap $states = null;

    /**
     * Strong references, not a WeakMap like $states — a double that's purely
     * a local variable in a test method (the common case) is already
     * garbage-collected, and therefore already gone from a WeakMap, the
     * instant that method returns, well before any #[After] hook runs. Only
     * appended to while $autoVerifyEnabled is true, so a suite that never uses
     * VerifiesDoubles never pays for this at all. Holds the DoubleState
     * directly, not the double object, since create() already has it in
     * scope and verifying never needs anything else.
     *
     * @var list<DoubleState>
     */
    private static array $pending = [];

    /**
     * Same strong-reference reasoning as $pending, same lifecycle
     * (enableAutoVerify() resets it, verifyAll() drains it) — the received()
     * counterpart, so both verbs get checked from the same #[After] hook.
     *
     * @var list<ReceivedAssertion>
     */
    private static array $pendingReceived = [];

    private static bool $autoVerifyEnabled = false;

    /**
     * Process-lifetime, unlike $pending/$pendingReceived above — a runner
     * (e.g. a test framework's own reporter) registers a listener once at
     * bootstrap and expects every subsequent test's checks to reach it, so
     * enableAutoVerify()/verifyAll() deliberately never touch this list.
     *
     * @var list<callable(CheckEvent): void>
     */
    private static array $listeners = [];

    private function __construct() {}

    /**
     * Opt-in escape hatch for `InvalidDoubleTargetException::isFinal()`:
     * rewrites `final class` out of a target's source before PHP ever
     * compiles it, so `Double::for()` can subclass it like any other class.
     *
     * Must be called before the target is referenced anywhere in the
     * process — the usual place is the very first line of your PHPUnit
     * bootstrap file, ahead of `require __DIR__.'/vendor/autoload.php'`
     * even. Once PHP has autoloaded and compiled a class as final, that's
     * permanent for the process; this can only affect classes it hasn't
     * loaded yet, not ones already loaded via an earlier `use`, `instanceof`,
     * or reflection call. Safe to call more than once or from more than one
     * place — a second call is a no-op.
     *
     * Global and process-wide by design, not scoped to a single target:
     * there's no way to know in advance which classes a later `Double::for()`
     * call will name. It only ever touches `final` immediately before
     * `class`, never a final method or constant, so nothing outside of
     * "this class can now be subclassed" changes for code that never gets
     * doubled.
     */
    public static function bypassFinals(): void
    {
        FinalBypass::enable();
    }

    /**
     * The real PHP return type stays the bare `object` — PHP has no syntax
     * for "whatever type this class-string names," only PHPStan/Psalm's
     * docblock generics do. That's sound, not just convenient: every
     * ordinary generated double actually `implements DoubleInterface` for
     * real (see that interface's own docblock), so the templated return
     * below is never a docblock fiction for that path. Only precise for the
     * single-target call — a multi-target intersection call doesn't have a
     * single T to infer from a variadic template like this one, so it falls
     * back to the same untyped `object` a caller would have gotten before
     * this existed. `override: true` breaks the T&DoubleInterface promise
     * too, deliberately: when $target actually has a reserved-name
     * collision, the return is an `OverriddenDouble` instead, which carries
     * the same seven verbs but isn't $target-shaped — see that class's own
     * docblock.
     *
     * `override` accepts `bool` in the variadic's own type only so it can be
     * passed by name at all (`Double::for($target, override: true)`) — PHP
     * validates a named argument against the variadic's declared type same
     * as a positional one, and `bool` isn't part of a real target's type
     * otherwise. Passed positionally instead of by name, or with anything
     * other than a real `bool`, it's rejected explicitly below rather than
     * silently treated as a target.
     *
     * @template T of object
     *
     * @param  class-string<T>|T  $targets
     * @return T&DoubleInterface
     */
    public static function for(string|object|bool ...$targets): object
    {
        $override = false;

        if (array_key_exists('override', $targets)) {
            if (! is_bool($targets['override'])) {
                throw new \InvalidArgumentException(sprintf(
                    'The `override` option for `Double::for()` must be a `bool`, `%s` given.',
                    get_debug_type($targets['override']),
                ));
            }

            $override = $targets['override'];
            unset($targets['override']);
            $targets = array_values($targets);
        }

        foreach ($targets as $target) {
            if (! is_string($target) && ! is_object($target)) {
                throw new \InvalidArgumentException(sprintf(
                    '`Double::for()` only accepts targets (a class-string or an object), plus a named '.
                    '`override` argument. An unnamed `%s` was passed.',
                    get_debug_type($target),
                ));
            }
        }

        if ($targets === []) {
            throw new \InvalidArgumentException('`Double::for()` requires at least one target.');
        }

        if (count($targets) > 1) {
            if ($override) {
                throw new \InvalidArgumentException(
                    '`Double::for()`\'s `override` option only supports a single target.',
                );
            }

            foreach ($targets as $target) {
                if (is_object($target)) {
                    // Which real instance a later ->passthru() should fall back to
                    // becomes ambiguous the moment more than one target is involved,
                    // so mixing one into a multi-target call is rejected rather than
                    // guessing.
                    throw new \InvalidArgumentException(
                        '`Double::for()` can\'t accept a real instance as a target when passing multiple targets.',
                    );
                }
            }

            return self::fabricateIntersection($targets, depth: 0);
        }

        $target = $targets[0];
        $knownInstance = is_object($target) ? $target : null;
        $targetClass = is_object($target) ? $target::class : $target;

        return self::fabricate($targetClass, depth: 0, knownInstance: $knownInstance, override: $override);
    }

    /**
     * @internal Used by SafeDefaultResolver for recursive Loose-mode
     * fabrication. depth=0 is for()'s own public path — a depth-0 double is
     * never marked fabricated, and only that path ever passes $knownInstance
     * or $override.
     */
    public static function fabricate(string $target, int $depth, ?object $knownInstance = null, bool $override = false): object
    {
        $generatedClass = (new ClassGenerator)->generate($target, $override);

        $instance = self::create($generatedClass, $target, $depth, $knownInstance);

        // $override only ever changes what generate() produces when $target
        // actually has a reserved-name collision to route around — a
        // colliding class is generated bare (IdentifiableDouble only, no
        // control verbs; see ClassGenerator::buildSource()), so it's never
        // a DoubleInterface itself. Non-colliding targets are completely
        // unaffected by $override: generate() produces the exact same class
        // either way, and this check is simply always true for them.
        return $instance instanceof DoubleInterface ? $instance : new OverriddenDouble($instance);
    }

    /**
     * @internal Used by SafeDefaultResolver (depth>0, fabricating an
     * intersection-typed return) and by for() itself (depth=0, a direct
     * multi-target double).
     *
     * @param  list<string>  $targets
     */
    public static function fabricateIntersection(array $targets, int $depth): object
    {
        $generatedClass = (new ClassGenerator)->generateForIntersection($targets);

        return self::create($generatedClass, implode('&', $targets), $depth);
    }

    private static function create(string $generatedClass, string $target, int $depth, ?object $knownInstance = null): object
    {
        $state = new DoubleState($target, self::deriveLabel($target));

        if ($depth > 0) {
            $state->markFabricated($depth);
        }

        if ($knownInstance !== null) {
            $state->rememberRealInstance($knownInstance);
        }

        $instance = $generatedClass::__td_instantiate();

        (new \ReflectionProperty($instance, '__td_identity'))->setValue($instance, new \stdClass);

        self::states()[$instance->__td_identity()] = $state;

        if (self::$autoVerifyEnabled) {
            self::$pending[] = $state;
        }

        return $instance;
    }

    /**
     * @internal Used only by DoubleControlMethods::verify() — this static
     * method exists only because the verification logic needs access to the
     * private double->state map, which that trait can't reach directly.
     */
    public static function verify(object $double): void
    {
        self::verifyState(self::stateFor($double));
    }

    private static function verifyState(DoubleState $state): void
    {
        $unmet = $state->unmetExpectations();

        if ($unmet === []) {
            PhpUnitIntegration::registerPass();
            self::notify(new CheckEvent($state->label(), method: null, passed: true, failure: null));

            return;
        }

        throw ExceptionFactory::unsatisfiedExpectation(
            $state->label(),
            array_map(
                static function (MethodExpectation $expectation) use ($state): UnsatisfiedExpectation {
                    $rawCalls = $state->callsFor($expectation->method());

                    // Only when exactly one call was ever made to this method does
                    // pairing "the expectation" against "the call" stop being a
                    // guess — with two or more, there's no fact-based way to tell
                    // which one this expectation was meant to match.
                    $comparison = count($rawCalls) === 1
                        ? $expectation->compareArguments($rawCalls[0])
                        : null;

                    return new UnsatisfiedExpectation(
                        method: $expectation->method(),
                        description: $expectation->describe(),
                        expectedMin: $expectation->minimumCalls(),
                        expectedMax: $expectation->maximumCalls(),
                        timesCalled: $expectation->timesMatched(),
                        otherObservedCalls: array_map(ArgumentFormatter::describe(...), $rawCalls),
                        argumentMismatch: ($comparison['kind'] ?? null) === 'arity' ? $comparison['text'] : null,
                        argumentComparisons: ($comparison['kind'] ?? null) === 'comparisons'
                            ? ArgumentLabeler::label($state->parameterNames($expectation->method()), $comparison['comparisons'])
                            : null,
                    );
                },
                $unmet,
            ),
            $state->isFabricated(),
        );
    }

    /**
     * Turns on auto-verification: every double created (and every
     * received() assertion made) from this call until the matching
     * verifyAll() is checked there instead of needing its own
     * verify()/assertion call. This is what PHPUnit's VerifiesDoubles trait
     * calls from its #[Before] hook; call it yourself to drive the same
     * "enable before, verify after" flow from any other test runner.
     *
     * Idempotent — safe to call every test — and resets both lists fresh, so
     * a prior test that somehow skipped its own verifyAll() (e.g. a fatal
     * error mid-test) can't leak stale entries into this one.
     *
     * A runner that interleaves tests (fibers/coroutines in one process)
     * can't just call this at the start of every test, since a context
     * switch may leave another test's state live — see
     * pauseAutoVerify()/resumeAutoVerify() for that case.
     */
    public static function enableAutoVerify(): void
    {
        self::$autoVerifyEnabled = true;
        self::$pending = [];
        self::$pendingReceived = [];
    }

    /**
     * Checks everything enableAutoVerify() has been collecting since it was
     * called, then turns auto-verification back off. This is what PHPUnit's
     * VerifiesDoubles trait calls from its #[After] hook; call it yourself
     * to drive the same "enable before, verify after" flow from any other
     * test runner.
     *
     * Returns how many checks passed — PHPUnit learns a check ran via
     * registerPass() bumping its own Assert counter, but that's invisible
     * to any other runner, which would otherwise see a test that only ever
     * used expects()/received() as having made no assertions at all. A
     * non-PHPUnit runner can use this count as its own "this test asserted
     * something" signal.
     *
     * Both lists are drained up front, before iterating, so a failure
     * partway through never leaves stale entries to leak into whichever
     * test's verifyAll() runs next. Turns auto-verification back off too —
     * otherwise a received() call in a test that never enables
     * auto-verification itself could still get swept into $pendingReceived
     * just because some earlier test in the suite enabled it and never
     * turned it back off, leaking a check into an unrelated test or, worse,
     * into process shutdown.
     */
    public static function verifyAll(): int
    {
        self::$autoVerifyEnabled = false;

        $pending = self::$pending;
        self::$pending = [];

        $pendingReceived = self::$pendingReceived;
        self::$pendingReceived = [];

        foreach ($pending as $state) {
            self::verifyState($state);
        }

        foreach ($pendingReceived as $assertion) {
            $assertion->check();
        }

        return count($pending) + count($pendingReceived);
    }

    /**
     * Lifts the live auto-verify state (whether it's enabled, pending
     * doubles, pending received() assertions) out into an opaque
     * AutoVerifySnapshot and resets the live state to off/empty, as if
     * verifyAll() had just drained it without doing any checking.
     *
     * For a runner that interleaves tests as fibers/coroutines in one
     * process: call this when a test suspends, to pause its in-flight state
     * so a sibling test resuming next doesn't sweep this one's doubles into
     * its own verifyAll(). Pair with resumeAutoVerify() when the suspended
     * test resumes.
     */
    public static function pauseAutoVerify(): AutoVerifySnapshot
    {
        $snapshot = new AutoVerifySnapshot(self::$autoVerifyEnabled, self::$pending, self::$pendingReceived);

        self::$autoVerifyEnabled = false;
        self::$pending = [];
        self::$pendingReceived = [];

        return $snapshot;
    }

    /**
     * Installs a previously paused AutoVerifySnapshot as the live
     * auto-verify state, replacing whatever is currently live. Callers are
     * expected to have already moved anything they care about out of the
     * live state (via pauseAutoVerify()) before calling this — it
     * overwrites, it doesn't merge.
     */
    public static function resumeAutoVerify(AutoVerifySnapshot $snapshot): void
    {
        self::$autoVerifyEnabled = $snapshot->enabled();
        self::$pending = $snapshot->pending();
        self::$pendingReceived = $snapshot->pendingReceived();
    }

    /**
     * Registers $listener to be notified with a CheckEvent every time an
     * expects()/allows()/received()/unused() check resolves, pass or fail —
     * at the moment it resolves, not batched at verify time. Meant for a
     * test framework's own reporting (e.g. logging every check into a
     * per-test timeline alongside its other assertions), so it stays live
     * across every test in the process; call clearListeners() to remove it.
     */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /**
     * Removes every listener registered via listen(). Mainly for this
     * library's own tests, which need isolation between test methods since
     * $listeners is otherwise a process-lifetime registry (see its
     * property docblock above).
     */
    public static function clearListeners(): void
    {
        self::$listeners = [];
    }

    /**
     * @internal Called by ExceptionFactory (every check failure in scope)
     * and by verifyState()/unused()/ReceivedAssertion::check() (their pass
     * branches) — never called directly for a check outside that scope
     * (see CheckEvent's own docblock for exactly which checks these are).
     */
    public static function notify(CheckEvent $event): void
    {
        foreach (self::$listeners as $listener) {
            $listener($event);
        }
    }

    /**
     * @internal
     *
     * Gates on `IdentifiableDouble`, not the full `DoubleInterface` — an
     * `override`-generated double (see `for()`) never implements
     * `DoubleInterface` itself (that's exactly the collision `override`
     * routes around), but it still needs real state, which is what
     * `OverriddenDouble` reaches for when it forwards each control verb.
     */
    public static function stateFor(object $double): DoubleState
    {
        if (! $double instanceof IdentifiableDouble) {
            throw new \LogicException('Object is not a `Double`-generated double.');
        }

        $state = self::states()[$double->__td_identity()] ?? null;

        if ($state === null) {
            throw new \LogicException('Object is not a `Double`-generated double.');
        }

        return $state;
    }

    /**
     * @internal
     */
    public static function registerExpectation(object $double, string $method, bool $required): MethodExpectation
    {
        $state = self::stateFor($double);

        self::assertConfigurable($state, $method);

        $expectation = new MethodExpectation($method, $required);
        $state->registerExpectation($expectation);

        return $expectation;
    }

    /**
     * @internal Used by DoubleControlMethods::strict() and, on the
     * `override` path, OverriddenDouble::strict() directly.
     */
    public static function strict(object $double): void
    {
        self::stateFor($double)->setMode(Mode::Strict);
    }

    /**
     * @internal Used by DoubleControlMethods::passthru() and, on the
     * `override` path, OverriddenDouble::passthru() directly. $realInstance,
     * if omitted, falls back to the real instance for() remembered
     * (DoubleState::knownInstance()). With neither, there's no real instance
     * at all to copy from — the double just keeps the uninitialized state it
     * already has (see PassthruInitializer::assertConstructible()), real
     * constructor never run. Either way, an unmatched call afterward runs on
     * the double itself, via the real body ClassGenerator generated for it
     * (see ClassGenerator::buildRealMethod() and
     * ProxyBehavior::handleUnmatchedCall()), not on a separate wrapped
     * object. That's what lets a self-call made from inside that real body
     * re-enter the double and hit a configured stub.
     */
    public static function passthru(object $double, ?object $realInstance = null): void
    {
        $state = self::stateFor($double);
        $realInstance ??= $state->knownInstance();

        if ($realInstance !== null) {
            PassthruInitializer::copyState($double, $realInstance, $state->target());
        } else {
            PassthruInitializer::assertConstructible($state->target());
        }

        $state->configurePassthru();
    }

    /**
     * @internal Used only by DoubleControlMethods::received(). Validates the
     * method name exactly like registerExpectation() does, but never
     * registers anything on DoubleState — the returned ReceivedAssertion
     * checks already-recorded calls, it never participates in live matching.
     */
    public static function received(object $double, string $method): ReceivedAssertion
    {
        $state = self::stateFor($double);

        self::assertConfigurable($state, $method);

        $assertion = new ReceivedAssertion($state, $method);

        if (self::$autoVerifyEnabled) {
            self::$pendingReceived[] = $assertion;
        }

        return $assertion;
    }

    /**
     * @internal Used only by DoubleControlMethods::unused(). Unlike
     * received(), there's no fluent chain to wait on — "no calls at all" is
     * a complete assertion on its own, so this checks immediately, the same
     * way verify() does, rather than deferring to __destruct()/verifyAll().
     */
    public static function unused(object $double): void
    {
        $state = self::stateFor($double);
        $calls = $state->calls();

        if ($calls === []) {
            PhpUnitIntegration::registerPass();
            self::notify(new CheckEvent($state->label(), method: null, passed: true, failure: null));

            return;
        }

        throw ExceptionFactory::unusedAssertion(
            $state->label(),
            array_map(
                static fn (array $call): string => sprintf('%s(%s)', $call['method'], ArgumentFormatter::describe($call['arguments'])),
                $calls,
            ),
            $state->isFabricated(),
        );
    }

    /**
     * Shared by registerExpectation() and received(): both need the same
     * three checks before they can do anything with $method — that it
     * exists, that it isn't static, and that it isn't magic.
     */
    private static function assertConfigurable(DoubleState $state, string $method): void
    {
        $declaringCandidate = $state->declaringCandidate($method);

        if ($declaringCandidate === null) {
            throw new UnknownMethodException(
                $state->target(),
                $method,
                $state->isFabricated(),
                DidYouMean::suggest($method, $state->declarableMethodNames()),
            );
        }

        if ($state->isStatic($declaringCandidate, $method)) {
            throw new StaticMethodException($state->target(), $method, $state->isFabricated());
        }

        if (str_starts_with($method, '__') && ! in_array($method, ClassGenerator::DOUBLEABLE_MAGIC_METHODS, true)) {
            throw new MagicMethodException($state->target(), $method, $state->isFabricated());
        }
    }

    private static function states(): \WeakMap
    {
        return self::$states ??= new \WeakMap;
    }

    /**
     * $target may be several "&"-joined names for an intersection double —
     * each candidate's short name is derived independently and rejoined with
     * "&" (e.g. "Fillable&Sized"), not derived from the joined string
     * itself, which would collapse to just the last candidate's name.
     */
    private static function deriveLabel(string $target): string
    {
        return implode('&', array_map(self::deriveShortName(...), explode('&', $target)));
    }

    private static function deriveShortName(string $target): string
    {
        $position = strrpos($target, '\\');

        return $position === false ? $target : substr($target, $position + 1);
    }
}
