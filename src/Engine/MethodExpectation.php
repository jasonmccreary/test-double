<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\Diagnostics\Pluralizer;
use JMac\Testing\Diagnostics\StringDiffer;
use JMac\Testing\Diagnostics\ValueFormatter;
use JMac\Testing\Matching\CaptureMatcher;
use JMac\Testing\Matching\EqualsMatcher;
use JMac\Testing\Matching\Matcher;
use JMac\Testing\Matching\NoneMatcher;
use JMac\Testing\Matching\RemainingMatcher;

/**
 * One configured expects()/allows() entry.
 *
 * Argument matching is the Matcher contract (JMac\Testing\Matching). A
 * bare literal passed to with() is wrapped in EqualsMatcher at this
 * boundary so the rest of the engine only ever deals in Matcher instances.
 */
final class MethodExpectation
{
    private const UNBOUNDED = PHP_INT_MAX;

    /** @var list<Matcher>|null */
    private ?array $argumentConstraints = null;

    /** @var list<mixed> */
    private array $returnValues = [];

    private bool $hasReturnConfigured = false;

    /** @var list<\Throwable> */
    private array $throwables = [];

    /** @var (callable(mixed...): mixed)|null */
    private $resolver = null;

    private int $minimumCalls;

    private int $maximumCalls;

    private int $timesMatched = 0;

    private bool $ordered = false;

    public function __construct(
        private readonly string $method,
        private readonly bool $required,
    ) {
        if ($required) {
            $this->minimumCalls = 1;
            $this->maximumCalls = 1;
        } else {
            $this->minimumCalls = 0;
            $this->maximumCalls = self::UNBOUNDED;
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * True for expects(), false for allows() — set once at construction and
     * never changed. Used by ProxyBehavior to decide whether an unmatched
     * call to this method should throw immediately (see
     * ExpectationCallMismatchException) instead of falling back to a mode's
     * default handling of unmatched calls.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    public function with(mixed ...$arguments): static
    {
        $constraints = array_map(
            static fn (mixed $argument): Matcher => $argument instanceof Matcher ? $argument : new EqualsMatcher($argument),
            $arguments,
        );

        foreach ($constraints as $index => $matcher) {
            if ($matcher instanceof RemainingMatcher && $index !== array_key_last($constraints)) {
                throw new \InvalidArgumentException(
                    '`Argument::remaining()` must be the last argument passed to `with()`.',
                );
            }

            if ($matcher instanceof NoneMatcher && count($constraints) !== 1) {
                throw new \InvalidArgumentException(
                    '`Argument::none()` must be the only argument passed to `with()`.',
                );
            }
        }

        $this->argumentConstraints = $constraints;

        return $this;
    }

    public function returns(mixed ...$values): static
    {
        if ($values === []) {
            throw new \InvalidArgumentException('`returns()` requires at least one value.');
        }

        $this->returnValues = $values;
        $this->hasReturnConfigured = true;
        $this->throwables = [];
        $this->resolver = null;

        return $this;
    }

    /**
     * Sequential, same as returns(): the first call throws $exceptions[0],
     * the second throws $exceptions[1], and so on, holding at the last one
     * for every call past the end of the list — exactly returns()'s
     * resolveReturn() indexing, not a separate mechanism.
     */
    public function throws(\Throwable ...$exceptions): static
    {
        if ($exceptions === []) {
            throw new \InvalidArgumentException('`throws()` requires at least one exception.');
        }

        $this->throwables = $exceptions;
        $this->hasReturnConfigured = true;
        $this->returnValues = [];
        $this->resolver = null;

        return $this;
    }

    /**
     * @param  callable(mixed...): mixed  $resolver
     */
    public function resolves(callable $resolver): static
    {
        $this->resolver = $resolver;
        $this->hasReturnConfigured = true;
        $this->returnValues = [];
        $this->throwables = [];

        return $this;
    }

    /**
     * The one count verb, overloaded to cover every bound shape — exact, at
     * least, at most, or a range — instead of a separate verb per shape.
     * $count is the positional slot: alone, it's the exact count; paired with
     * $maximum, it's the lower bound of a range. $minimum exists only so
     * "at least" can be expressed without implying an exact upper bound.
     */
    public function times(?int $count = null, ?int $maximum = null, ?int $minimum = null): static
    {
        // $count and $minimum are both trying to set the same lower bound, so
        // passing both is rejected as ambiguous rather than picking one silently.
        if ($count !== null && $minimum !== null) {
            throw new \InvalidArgumentException(
                '`times()` can\'t take both a positional count and a named minimum — use one or the other.',
            );
        }

        $resolvedMinimum = $minimum ?? $count;
        $resolvedMaximum = $maximum ?? $count;

        if ($resolvedMinimum === null && $resolvedMaximum === null) {
            throw new \InvalidArgumentException('`times()` requires a count, a minimum, or a maximum.');
        }

        $resolvedMinimum ??= 0;
        $resolvedMaximum ??= self::UNBOUNDED;

        if ($resolvedMinimum > $resolvedMaximum) {
            throw new \InvalidArgumentException(sprintf(
                '`times()`: minimum (%d) can\'t be greater than maximum (%d).',
                $resolvedMinimum,
                $resolvedMaximum,
            ));
        }

        $this->minimumCalls = $resolvedMinimum;
        $this->maximumCalls = $resolvedMaximum;

        return $this;
    }

    public function never(): static
    {
        return $this->times(0);
    }

    /**
     * Marks this expectation as participating in call-order enforcement.
     * Deliberately just a flag — the slot bookkeeping and violation check
     * live in DoubleState/ProxyBehavior, which already have
     * registration-order visibility across every expectation on a double.
     */
    public function ordered(): static
    {
        $this->ordered = true;

        return $this;
    }

    public function isOrdered(): bool
    {
        return $this->ordered;
    }

    public function matchesArguments(array $arguments): bool
    {
        if ($this->argumentConstraints === null) {
            return true;
        }

        $constraints = $this->argumentConstraints;

        // Argument::none() as the sole constraint asserts the call took no
        // arguments at all — with() already guarantees it can only ever
        // appear alone, so there's no positional matching left to do here.
        if ($constraints !== [] && $constraints[0] instanceof NoneMatcher) {
            return $arguments === [];
        }

        // Argument::remaining() as the trailing constraint means "however
        // many further arguments there are, they're unconstrained" — drop
        // it and require only a minimum count instead of an exact one.
        // with() already guarantees it can only ever be the last element.
        $matchesRemaining = $constraints !== [] && end($constraints) instanceof RemainingMatcher;

        if ($matchesRemaining) {
            array_pop($constraints);
        }

        if ($matchesRemaining ? count($arguments) < count($constraints) : count($constraints) !== count($arguments)) {
            return false;
        }

        foreach ($constraints as $index => $matcher) {
            if (! $matcher->matches($arguments[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares $arguments position by position against this expectation's
     * constraints, for the one case where that pairing is a plain fact
     * rather than a guess: exactly one real call was ever observed for this
     * method (see Double::verifyState()). Null when there's nothing to
     * compare — with() was never called, or $arguments actually matches.
     *
     * Positional and unlabeled deliberately: this stays ignorant of real
     * parameter names (a reflection concern, resolved from the target class
     * — see DoubleState::parameterNames()) so the caller can attach labels
     * without this needing any awareness of it.
     *
     * @return array{kind: 'arity', text: string}
     *                                            | array{kind: 'comparisons', comparisons: list<array{index: int, differs: bool, text: string}>}
     *                                            | null
     */
    public function compareArguments(array $arguments): ?array
    {
        if ($this->argumentConstraints === null) {
            return null;
        }

        $constraints = $this->argumentConstraints;

        if ($constraints !== [] && $constraints[0] instanceof NoneMatcher) {
            return $arguments === []
                ? null
                : ['kind' => 'arity', 'text' => sprintf('expected no arguments, got %s', Pluralizer::pluralize(count($arguments), 'argument', 'arguments'))];
        }

        $matchesRemaining = $constraints !== [] && end($constraints) instanceof RemainingMatcher;

        if ($matchesRemaining) {
            array_pop($constraints);
        }

        $expectedCount = count($constraints);
        $actualCount = count($arguments);

        if ($matchesRemaining ? $actualCount < $expectedCount : $expectedCount !== $actualCount) {
            return ['kind' => 'arity', 'text' => sprintf(
                'expected %s, got %s',
                Pluralizer::pluralize($expectedCount, 'argument', 'arguments'),
                Pluralizer::pluralize($actualCount, 'argument', 'arguments'),
            )];
        }

        $comparisons = [];
        $anyDiffers = false;

        foreach ($constraints as $index => $matcher) {
            $actual = $arguments[$index];

            if ($matcher->matches($actual)) {
                $comparisons[] = ['index' => $index, 'differs' => false, 'text' => ValueFormatter::describe($actual)];

                continue;
            }

            $anyDiffers = true;
            $comparisons[] = ['index' => $index, 'differs' => true, 'text' => self::describeArgumentDiff($matcher, $actual)];
        }

        // Argument::remaining()'s trailing arguments are unconstrained by
        // definition — always context, never a diff — but still shown, so
        // the reader sees the whole actual call, not just its constrained
        // prefix.
        if ($matchesRemaining) {
            foreach (array_slice($arguments, $expectedCount) as $offset => $actual) {
                $comparisons[] = ['index' => $expectedCount + $offset, 'differs' => false, 'text' => ValueFormatter::describe($actual)];
            }
        }

        return $anyDiffers ? ['kind' => 'comparisons', 'comparisons' => $comparisons] : null;
    }

    /**
     * The "- expected\n+ actual" pair for one differing argument. Only
     * EqualsMatcher — a bare literal passed to with() — wraps a raw value
     * worth diffing directly: long strings get StringDiffer, and two arrays
     * whose ValueFormatter::describe() output would otherwise collide (same
     * element count, different content) get a full var_export() dump
     * instead of two identical-looking "array(N)" lines. Every other shape
     * (type checks, patterns, predicates, short values) falls back to the
     * matcher's own describe() paired against the actual value.
     */
    private static function describeArgumentDiff(Matcher $matcher, mixed $actual): string
    {
        if ($matcher instanceof EqualsMatcher) {
            $expected = $matcher->expected();

            if (is_string($expected) && is_string($actual) && strlen($expected) + strlen($actual) >= StringDiffer::MIN_LENGTH_TO_DIFF) {
                return StringDiffer::diff($expected, $actual);
            }

            if (is_array($expected) && is_array($actual) && ValueFormatter::describe($expected) === ValueFormatter::describe($actual)) {
                return sprintf("- %s\n+ %s", var_export($expected, true), var_export($actual, true));
            }
        }

        return sprintf("- %s\n+ %s", $matcher->describe(), ValueFormatter::describe($actual));
    }

    /**
     * @param  list<mixed>  $arguments  Real call arguments, used only to feed any
     *                                  Argument::capture() matcher — matches()
     *                                  itself must stay side-effect-free since
     *                                  it's called speculatively on losing
     *                                  candidates too (see ProxyBehavior::findMatch()).
     */
    public function recordMatch(array $arguments = []): void
    {
        $this->timesMatched++;

        if ($this->argumentConstraints !== null) {
            foreach ($this->argumentConstraints as $index => $matcher) {
                if ($matcher instanceof CaptureMatcher) {
                    $matcher->capture($arguments[$index]);
                }
            }
        }
    }

    public function timesMatched(): int
    {
        return $this->timesMatched;
    }

    public function minimumCalls(): int
    {
        return $this->minimumCalls;
    }

    public function maximumCalls(): int
    {
        return $this->maximumCalls;
    }

    public function isSatisfied(): bool
    {
        return $this->timesMatched >= $this->minimumCalls;
    }

    public function exceedsMaximum(): bool
    {
        return $this->timesMatched > $this->maximumCalls;
    }

    public function hasReturnConfigured(): bool
    {
        return $this->hasReturnConfigured;
    }

    public function resolveReturn(array $arguments): mixed
    {
        if ($this->throwables !== []) {
            $index = min($this->timesMatched - 1, count($this->throwables) - 1);

            throw $this->throwables[$index];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)(...$arguments);
        }

        $index = min($this->timesMatched - 1, count($this->returnValues) - 1);

        return $this->returnValues[$index];
    }

    public function describe(): string
    {
        return sprintf(
            'expected `%s(%s)` to %s, but it was %s',
            $this->method,
            $this->describeArguments(),
            $this->describeExpectedBound(),
            $this->describeActualCount(),
        );
    }

    /**
     * The "(...)" part only — see CallListFormatter::describe()'s own
     * docblock for the same convention. Split out of describe() so a caller
     * that already has its own sentence around a call (e.g.
     * ExpectationCallMismatchFields listing several configured expectations
     * side by side) can reuse just the argument pattern, not the whole
     * "expected ... to be called ..." sentence.
     */
    public function describeArguments(): string
    {
        return $this->argumentConstraints === null
            ? 'any arguments'
            : implode(', ', array_map(static fn (Matcher $matcher): string => $matcher->describe(), $this->argumentConstraints));
    }

    private function describeExpectedBound(): string
    {
        if ($this->minimumCalls === 0 && $this->maximumCalls === 0) {
            return 'never be called';
        }

        if ($this->minimumCalls === $this->maximumCalls) {
            return 'be called exactly '.Pluralizer::pluralize($this->minimumCalls, 'time', 'times');
        }

        if ($this->minimumCalls === 0 && $this->maximumCalls !== self::UNBOUNDED) {
            return 'be called at most '.Pluralizer::pluralize($this->maximumCalls, 'time', 'times');
        }

        if ($this->maximumCalls === self::UNBOUNDED) {
            return 'be called at least '.Pluralizer::pluralize($this->minimumCalls, 'time', 'times');
        }

        return sprintf('be called between %d and %d times', $this->minimumCalls, $this->maximumCalls);
    }

    private function describeActualCount(): string
    {
        return $this->timesMatched === 0
            ? 'never called'
            : 'called '.Pluralizer::pluralize($this->timesMatched, 'time', 'times');
    }
}
