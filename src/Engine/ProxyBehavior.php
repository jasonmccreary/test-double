<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\Diagnostics\ArgumentComparison;
use JMac\Testing\Diagnostics\ArgumentFormatter;
use JMac\Testing\Double;

/**
 * @internal
 *
 * The call-interception logic every generated double's overridden methods
 * funnel through.
 */
final class ProxyBehavior
{
    // Takes the double instance itself, not just its DoubleState, because
    // resolving a self/static safe-default return needs the actual object
    // to return (see SafeDefaultResolver).
    public static function intercept(object $double, string $method, array $arguments): mixed
    {
        $state = Double::stateFor($double);

        $state->recordCall($method, $arguments);

        $candidates = $state->expectationsFor($method);
        $expectation = self::findMatch($candidates, $arguments);

        if ($expectation === null) {
            // expects() holds this method to a stricter standard than the
            // double's own mode: once any expectation is required for it, an
            // unmatched call is always a mismatch to report, never a call
            // Loose mode is allowed to shrug off with a safe default. A
            // Strict double would reject it anyway via handleUnmatchedCall()
            // below, but this fires first regardless of mode since it's a
            // strictly more specific diagnosis than that blanket rejection.
            if (self::hasRequiredCandidate($candidates)) {
                throw self::expectationCallMismatch($state, $method, $arguments, $candidates);
            }

            return self::handleUnmatchedCall($state, $method, $arguments, $double);
        }

        $expectation->recordMatch($arguments);

        self::enforceOrder($state, $expectation);

        if ($expectation->exceedsMaximum()) {
            throw ExceptionFactory::expectationCallLimitExceeded(
                $state->label(),
                $method,
                ArgumentFormatter::describe($arguments),
                $expectation->maximumCalls(),
                $expectation->timesMatched(),
                $state->isFabricated(),
                self::countOtherMatchingExpectations($state, $method, $arguments, $expectation),
            );
        }

        if (! $expectation->hasReturnConfigured()) {
            // Same safe-default-by-return-type resolver as Loose mode's
            // unmatched-call fallback below — there's only one in the codebase.
            return SafeDefaultResolver::resolveForMethod($state, $method, $double);
        }

        return $expectation->resolveReturn($arguments);
    }

    // Last-registered expectation whose arguments match *and still has room
    // under its own times()/maximumCalls() budget* wins, falling through to
    // less-recently-registered candidates once one is exhausted. Only once
    // every matching candidate is exhausted does the most-recently-registered
    // one get reused, purely so the "exceeds maximum" error still has a
    // concrete expectation to report against.
    /**
     * @param  list<MethodExpectation>  $candidates
     */
    private static function findMatch(array $candidates, array $arguments): ?MethodExpectation
    {
        $fallback = null;

        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            if (! $candidates[$i]->matchesArguments($arguments)) {
                continue;
            }

            if ($candidates[$i]->timesMatched() < $candidates[$i]->maximumCalls()) {
                return $candidates[$i];
            }

            $fallback ??= $candidates[$i];
        }

        return $fallback;
    }

    /**
     * How many *other* expectations registered for $method also match this
     * call's arguments — walking the exact same candidate pool findMatch()
     * already checked. Surfaced in expectationCallLimitExceeded()'s message
     * so failure mode 1a (a starved expectation, not the one actually
     * reported, is the real problem) is self-diagnosing instead of
     * requiring a source-level read of registration order to spot.
     */
    private static function countOtherMatchingExpectations(
        DoubleState $state,
        string $method,
        array $arguments,
        MethodExpectation $selected,
    ): int {
        $count = 0;

        foreach ($state->expectationsFor($method) as $candidate) {
            if ($candidate !== $selected && $candidate->matchesArguments($arguments)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Enforces ordered() sequencing for whichever expectation findMatch()
     * already selected; never changes that selection.
     */
    private static function enforceOrder(DoubleState $state, MethodExpectation $expectation): void
    {
        if (! $expectation->isOrdered()) {
            return;
        }

        $ordered = $state->orderedExpectations();
        $slot = array_search($expectation, $ordered, true);

        // Reject only regression to before the furthest slot already reached —
        // reaching a later slot without every slot in between firing is allowed.
        // A skipped required step still surfaces via the ordinary
        // unmet-expectation check at verify() time.
        if ($slot < $state->orderCursor()) {
            throw ExceptionFactory::outOfOrderCall(
                $state->label(),
                $expectation->method(),
                $ordered[$state->orderCursor()]->method(),
                $state->isFabricated(),
            );
        }

        $state->advanceOrderCursor($slot);
    }

    private static function handleUnmatchedCall(DoubleState $state, string $method, array $arguments, object $double): mixed
    {
        return match ($state->mode()) {
            Mode::Strict => throw self::unexpectedCall($state, $method, $arguments),
            Mode::Loose => SafeDefaultResolver::resolveForMethod($state, $method, $double),
            // Runs the double's own "__td_real_*" body (see
            // ClassGenerator::buildRealMethod()) instead of delegating to a
            // separate object — $this stays the double throughout, so a
            // self-call inside that real body re-enters the double's own
            // overrides rather than escaping it.
            Mode::Passthru => $double->{'__td_real_'.$method}(...$arguments),
        };
    }

    private static function unexpectedCall(DoubleState $state, string $method, array $arguments): \Throwable
    {
        // callsFor($method) already includes this very call — recordCall() above ran
        // before findMatch() — so the last entry is dropped to leave only calls that
        // happened before this one.
        $otherRawCalls = array_slice($state->callsFor($method), 0, -1);

        return ExceptionFactory::unexpectedCall(
            $state->label(),
            $method,
            ArgumentFormatter::describe($arguments),
            $state->isFabricated(),
            array_map(ArgumentFormatter::describe(...), $otherRawCalls),
            self::comparisonsAgainstThePriorCall($state, $method, $arguments, $otherRawCalls),
        );
    }

    /**
     * Exactly one other call already observed for this method is the one
     * case where pairing this failing call against it, argument by
     * argument, is a fact rather than a guess — with two or more, there's
     * no fact-based way to tell which one it "should" have resembled. Same
     * criterion Double::verifyState() uses for the symmetric
     * never-satisfied-expectation case.
     *
     * Reuses MethodExpectation::compareArguments() rather than a second
     * comparison algorithm: the prior call's own arguments become literal
     * with() constraints, so this failing call is diffed against them
     * exactly the way it would be against a real expects()/allows().
     *
     * @param  list<array>  $otherRawCalls
     * @return list<ArgumentComparison>|null
     */
    private static function comparisonsAgainstThePriorCall(DoubleState $state, string $method, array $arguments, array $otherRawCalls): ?array
    {
        if (count($otherRawCalls) !== 1) {
            return null;
        }

        $comparison = (new MethodExpectation($method, required: false))
            ->with(...$otherRawCalls[0])
            ->compareArguments($arguments);

        return ($comparison['kind'] ?? null) === 'comparisons'
            ? ArgumentLabeler::label($state->parameterNames($method), $comparison['comparisons'])
            : null;
    }

    /**
     * @param  list<MethodExpectation>  $candidates
     */
    private static function hasRequiredCandidate(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate->isRequired()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<MethodExpectation>  $candidates  every expectation registered for $method —
     *                                               always non-empty here, since this is only
     *                                               reached once hasRequiredCandidate() confirms
     *                                               at least one exists
     */
    private static function expectationCallMismatch(DoubleState $state, string $method, array $arguments, array $candidates): \Throwable
    {
        return ExceptionFactory::expectationCallMismatch(
            $state->label(),
            $method,
            ArgumentFormatter::describe($arguments),
            $state->isFabricated(),
            array_map(static fn (MethodExpectation $candidate): string => $candidate->describeArguments(), $candidates),
            self::comparisonsAgainstTheOnlyCandidate($state, $method, $arguments, $candidates),
            $state->mode() === Mode::Passthru,
        );
    }

    /**
     * Exactly one expectation registered for $method is the one case where
     * pairing this failing call against it, argument by argument, is a fact
     * rather than a guess between several candidates — same criterion
     * comparisonsAgainstThePriorCall() uses for the symmetric unexpected-call
     * case. Unlike that one, no synthetic MethodExpectation is needed here:
     * $candidates[0] already *is* a real, registered expectation.
     *
     * @param  list<MethodExpectation>  $candidates
     * @return list<ArgumentComparison>|null
     */
    private static function comparisonsAgainstTheOnlyCandidate(DoubleState $state, string $method, array $arguments, array $candidates): ?array
    {
        if (count($candidates) !== 1) {
            return null;
        }

        $comparison = $candidates[0]->compareArguments($arguments);

        return ($comparison['kind'] ?? null) === 'comparisons'
            ? ArgumentLabeler::label($state->parameterNames($method), $comparison['comparisons'])
            : null;
    }
}
