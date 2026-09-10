<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Exceptions;

use JMac\Testing\Diagnostics\ArgumentComparison;
use JMac\Testing\Diagnostics\StringDiffer;
use JMac\Testing\Diagnostics\UnsatisfiedExpectation;
use JMac\Testing\Exceptions\ExpectationCallLimitExceededException;
use JMac\Testing\Exceptions\ExpectationCallMismatchException;
use JMac\Testing\Exceptions\FabricationLimitExceededException;
use JMac\Testing\Exceptions\InvalidDoubleTargetException;
use JMac\Testing\Exceptions\MagicMethodException;
use JMac\Testing\Exceptions\ModeConfigurationException;
use JMac\Testing\Exceptions\OutOfOrderCallException;
use JMac\Testing\Exceptions\PassthruAutoInstantiationException;
use JMac\Testing\Exceptions\ReservedNameCollisionException;
use JMac\Testing\Exceptions\StaticMethodException;
use JMac\Testing\Exceptions\UnexpectedCallException;
use JMac\Testing\Exceptions\UnknownMethodException;
use JMac\Testing\Exceptions\UnsatisfiedExpectationException;
use JMac\Testing\Exceptions\UnsatisfiedReceivedAssertionException;
use JMac\Testing\Exceptions\UnusedAssertionException;

final class ExceptionMessagesTest extends GoldenFileTestCase
{
    public function test_renders_unexpected_call(): void
    {
        $exception = new UnexpectedCallException('BookRepository', 'count', '');

        $this->assertMatchesGolden('unexpected-call', $exception->getMessage());
    }

    /**
     * The motivating scenario for call correlation: expects('bar')->with('baz')
     * never fires because the code under test actually called bar('Baz').
     */
    public function test_renders_unsatisfied_expectation_with_call_correlation(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'bar',
            description: "expected `bar('baz')` to be called exactly 1 time, but it was never called",
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ["'Baz'"],
            argumentComparisons: [
                new ArgumentComparison(label: 'value', differs: true, text: "- 'baz'\n+ 'Baz'"),
            ],
        );
        $exception = new UnsatisfiedExpectationException('foo', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-with-correlation', $exception->getMessage());
    }

    /**
     * A single differing argument that's long enough to be worth eliding —
     * StringDiffer::diff() replaces the plain "- expected\n+ actual" pair
     * with a windowed snippet around the differing region.
     */
    public function test_renders_unsatisfied_expectation_with_a_long_string_argument_diff(): void
    {
        $expected = str_repeat('a', 30).'baz'.str_repeat('a', 30);
        $actual = str_repeat('a', 30).'BAZ'.str_repeat('a', 30);

        $expectation = new UnsatisfiedExpectation(
            method: 'render',
            description: sprintf('expected `render(%s)` to be called exactly 1 time, but it was never called', var_export($expected, true)),
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [var_export($actual, true)],
            argumentComparisons: [
                new ArgumentComparison(label: 'query', differs: true, text: StringDiffer::diff($expected, $actual)),
            ],
        );
        $exception = new UnsatisfiedExpectationException('foo', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-with-long-string-diff', $exception->getMessage());
    }

    /**
     * The headline case: a multi-line string (e.g. a JSON payload) diffs
     * line by line rather than as one blob with a raw newline embedded in
     * a single-quoted value.
     */
    public function test_renders_unsatisfied_expectation_with_a_multi_line_string_argument_diff(): void
    {
        $expected = "{\n    \"id\": 1,\n    \"name\": \"baz\",\n    \"active\": true\n}";
        $actual = "{\n    \"id\": 1,\n    \"name\": \"Baz\",\n    \"active\": true\n}";

        $expectation = new UnsatisfiedExpectation(
            method: 'save',
            description: sprintf('expected `save(%s)` to be called exactly 1 time, but it was never called', var_export($expected, true)),
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [var_export($actual, true)],
            argumentComparisons: [
                new ArgumentComparison(label: 'body', differs: true, text: StringDiffer::diff($expected, $actual)),
            ],
        );
        $exception = new UnsatisfiedExpectationException('foo', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-with-multi-line-string-diff', $exception->getMessage());
    }

    /**
     * Several differing arguments, alongside ones that still matched — the
     * labeled block format doesn't collapse this away the way the flat,
     * single-line summary it replaced had to.
     */
    public function test_renders_unsatisfied_expectation_with_multiple_argument_comparisons(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'move',
            description: "expected `move('baz', 5, 'y', 'z')` to be called exactly 1 time, but it was never called",
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ["'Baz', 6, 'y', 'z'"],
            argumentComparisons: [
                new ArgumentComparison(label: 'name', differs: true, text: "- 'baz'\n+ 'Baz'"),
                new ArgumentComparison(label: 'id', differs: true, text: "- 5\n+ 6"),
                new ArgumentComparison(label: 'status', differs: false, text: "'y'"),
                new ArgumentComparison(label: 'owner', differs: false, text: "'z'"),
            ],
        );
        $exception = new UnsatisfiedExpectationException('foo', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-with-multiple-argument-comparisons', $exception->getMessage());
    }

    /**
     * More than three other observed calls collapses to "and N more" rather
     * than listing every one — see CallListFormatter; this cap exists on
     * both correlation features, not just the unexpected-call one.
     */
    public function test_renders_unsatisfied_expectation_with_capped_call_correlation(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'bar',
            description: 'expected `bar(6)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ['1', '2', '3', '4'],
        );
        $exception = new UnsatisfiedExpectationException('foo', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-with-correlation-capped', $exception->getMessage());
    }

    public function test_renders_unsatisfied_expectation_with_no_observed_calls_at_all(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'delete',
            description: 'expected `delete(any arguments)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $exception = new UnsatisfiedExpectationException('BookRepository', [$expectation]);

        $this->assertMatchesGolden('unsatisfied-expectation-without-correlation', $exception->getMessage());
    }

    public function test_renders_multiple_unsatisfied_expectations(): void
    {
        $first = new UnsatisfiedExpectation(
            method: 'save',
            description: 'expected `save(any arguments)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $second = new UnsatisfiedExpectation(
            method: 'delete',
            description: 'expected `delete(any arguments)` to be called at least 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: PHP_INT_MAX,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $exception = new UnsatisfiedExpectationException('BookRepository', [$first, $second]);

        $this->assertMatchesGolden('unsatisfied-expectations-multiple', $exception->getMessage());
    }

    /**
     * When one of several unsatisfied expectations did observe calls to its
     * method, that's evidence this entry is a real mismatch rather than a
     * symptom of some other expectation's failure — shown inline rather
     * than as renderSingle()'s full paragraph, to keep the list scannable.
     */
    public function test_renders_multiple_unsatisfied_expectations_with_correlation(): void
    {
        $first = new UnsatisfiedExpectation(
            method: 'save',
            description: 'expected `save(any arguments)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $second = new UnsatisfiedExpectation(
            method: 'delete',
            description: 'expected `delete(1)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ['2'],
        );
        $exception = new UnsatisfiedExpectationException('BookRepository', [$first, $second]);

        $this->assertMatchesGolden('unsatisfied-expectations-multiple-with-correlation', $exception->getMessage());
    }

    /**
     * Same cap as the single-expectation correlation — see CallListFormatter.
     */
    public function test_renders_multiple_unsatisfied_expectations_with_capped_correlation(): void
    {
        $first = new UnsatisfiedExpectation(
            method: 'save',
            description: 'expected `save(any arguments)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $second = new UnsatisfiedExpectation(
            method: 'delete',
            description: 'expected `delete(6)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ['1', '2', '3', '4'],
        );
        $exception = new UnsatisfiedExpectationException('BookRepository', [$first, $second]);

        $this->assertMatchesGolden('unsatisfied-expectations-multiple-with-capped-correlation', $exception->getMessage());
    }

    public function test_renders_call_limit_exceeded(): void
    {
        $exception = new ExpectationCallLimitExceededException('BookRepository', 'delete', '1', 1, 2);

        $this->assertMatchesGolden('call-limit-exceeded', $exception->getMessage());
    }

    /**
     * Failure mode 1a's motivating scenario: a generic catch-all steals a
     * call meant for a still-unconsumed, more-specific expectation and then
     * throws once its own budget is spent — the reported expectation is
     * never the real problem. Naming the other still-matching expectation(s)
     * makes that self-diagnosing instead of requiring a source-level read of
     * registration order.
     */
    public function test_renders_call_limit_exceeded_with_one_other_matching_expectation(): void
    {
        $exception = new ExpectationCallLimitExceededException(
            'OutputInterface',
            'writeln',
            "' <fg=green;options=bold>DONE</>', 32",
            1,
            2,
            otherMatchingExpectations: 1,
        );

        $this->assertMatchesGolden('call-limit-exceeded-with-other-match', $exception->getMessage());
    }

    public function test_renders_call_limit_exceeded_with_multiple_other_matching_expectations(): void
    {
        $exception = new ExpectationCallLimitExceededException(
            'OutputInterface',
            'writeln',
            "' <fg=green;options=bold>DONE</>', 32",
            1,
            2,
            otherMatchingExpectations: 2,
        );

        $this->assertMatchesGolden('call-limit-exceeded-with-other-matches', $exception->getMessage());
    }

    public function test_renders_unexpected_call_on_a_fabricated_double_with_provenance_note(): void
    {
        $exception = new UnexpectedCallException('Book', 'getAuthor', '', fabricated: true);

        $this->assertMatchesGolden('unexpected-call-fabricated', $exception->getMessage());
    }

    public function test_renders_passthru_auto_instantiation_for_an_interface(): void
    {
        $exception = PassthruAutoInstantiationException::isInterface('BookRepositoryInterface');

        $this->assertMatchesGolden('passthru-auto-instantiation-interface', $exception->getMessage());
    }

    public function test_renders_unknown_method(): void
    {
        $exception = new UnknownMethodException('BookRepositoryInterface', 'bogus');

        $this->assertMatchesGolden('unknown-method', $exception->getMessage());
    }

    public function test_renders_unknown_method_with_a_suggestion(): void
    {
        $exception = new UnknownMethodException('BookRepositoryInterface', 'sav', suggestion: 'save');

        $this->assertMatchesGolden('unknown-method-with-suggestion', $exception->getMessage());
    }

    public function test_renders_mode_configuration(): void
    {
        $exception = new ModeConfigurationException('BookRepository', 'Strict', 'Strict');

        $this->assertMatchesGolden('mode-configuration', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_does_not_exist(): void
    {
        $exception = InvalidDoubleTargetException::doesNotExist('NoSuchClass');

        $this->assertMatchesGolden('invalid-double-target-does-not-exist', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_is_final(): void
    {
        $exception = InvalidDoubleTargetException::isFinal('FinalLogger');

        $this->assertMatchesGolden('invalid-double-target-is-final', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_has_abstract_static_method(): void
    {
        $exception = InvalidDoubleTargetException::hasAbstractStaticMethod('StaticMethodInterface', 'make');

        $this->assertMatchesGolden('invalid-double-target-has-abstract-static-method', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_has_abstract_magic_method(): void
    {
        $exception = InvalidDoubleTargetException::hasAbstractMagicMethod('MagicMethodInterface', '__call');

        $this->assertMatchesGolden('invalid-double-target-has-abstract-magic-method', $exception->getMessage());
    }

    /**
     * Plain strings in, no real PHP 8.4 property-hook syntax involved — this
     * renders fine on every supported PHP version, unlike the ClassGenerator
     * regression tests that actually declare a hooked property fixture.
     */
    public function test_renders_invalid_double_target_has_abstract_property_hook(): void
    {
        $exception = InvalidDoubleTargetException::hasAbstractPropertyHook('HookedPropertyInterface', 'displayName');

        $this->assertMatchesGolden('invalid-double-target-has-abstract-property-hook', $exception->getMessage());
    }

    public function test_renders_static_method(): void
    {
        $exception = new StaticMethodException('HasStaticMethod', 'make');

        $this->assertMatchesGolden('static-method', $exception->getMessage());
    }

    public function test_renders_magic_method(): void
    {
        $exception = new MagicMethodException('HasMagicMethod', '__call');

        $this->assertMatchesGolden('magic-method', $exception->getMessage());
    }

    public function test_renders_out_of_order_call(): void
    {
        $exception = new OutOfOrderCallException('Connection', 'open', 'close');

        $this->assertMatchesGolden('out-of-order-call', $exception->getMessage());
    }

    public function test_renders_unexpected_call_with_arguments(): void
    {
        $exception = new UnexpectedCallException('BookRepository', 'save', "5, 'Alice'");

        $this->assertMatchesGolden('unexpected-call-with-arguments', $exception->getMessage());
    }

    /**
     * The symmetric extension: the same argument-by-argument diff the
     * verify() path shows for its one-observed-call case, mirrored onto an
     * unexpected call that matched no configured expectation — diffed
     * against the one other call already observed for this method, not
     * against whatever's configured.
     */
    public function test_renders_unexpected_call_with_correlation(): void
    {
        $exception = new UnexpectedCallException(
            'BookRepository',
            'find',
            '456',
            otherObservedCalls: ['123'],
            argumentComparisons: [new ArgumentComparison(label: 'id', differs: true, text: "- 123\n+ 456")],
        );

        $this->assertMatchesGolden('unexpected-call-with-correlation', $exception->getMessage());
    }

    /**
     * Both correlation features share CallListFormatter's cap — more than
     * three prior calls collapses to "and N more" instead of a wall of text,
     * which would defeat its own purpose once a method's been called many
     * times legitimately (e.g. once per loop iteration with a different id).
     */
    public function test_renders_unexpected_call_with_capped_correlation(): void
    {
        $exception = new UnexpectedCallException(
            'BookRepository',
            'find',
            '6',
            otherObservedCalls: ['1', '2', '3', '4', '5'],
        );

        $this->assertMatchesGolden('unexpected-call-with-correlation-capped', $exception->getMessage());
    }

    /**
     * expects()'s per-method strictness, not Strict mode's blanket policy —
     * exactly one expectation registered for `find` is the one case where
     * diffing this failing call against it, argument by argument, is a fact
     * rather than a guess between several candidates. Same shape as
     * UnexpectedCallException's own correlation diff, but against the real
     * configured expectation instead of a coincidental prior call.
     */
    public function test_renders_expectation_call_mismatch(): void
    {
        $exception = new ExpectationCallMismatchException(
            'BookRepository',
            'find',
            '456',
            argumentComparisons: [new ArgumentComparison(label: 'id', differs: true, text: "- 123\n+ 456")],
        );

        $this->assertMatchesGolden('expectation-call-mismatch', $exception->getMessage());
    }

    /**
     * Two or more expectations registered for `find` leaves no fact-based
     * way to say which one this call "should" have matched, so this lists
     * every configured pattern instead of guessing which to diff against.
     */
    public function test_renders_expectation_call_mismatch_with_multiple_candidates(): void
    {
        $exception = new ExpectationCallMismatchException(
            'BookRepository',
            'find',
            '3',
            configuredCalls: ['1', '2'],
        );

        $this->assertMatchesGolden('expectation-call-mismatch-with-multiple-candidates', $exception->getMessage());
    }

    /**
     * Passthru mode's whole premise is "real behavior unless overridden," so
     * without this note, a mismatched expects() call rejecting instead of
     * delegating to the real object reads like a bug, not the deliberate
     * mode-independent contract it is.
     */
    public function test_renders_expectation_call_mismatch_in_passthru_mode(): void
    {
        $exception = new ExpectationCallMismatchException(
            'BookRepository',
            'find',
            '456',
            argumentComparisons: [new ArgumentComparison(label: 'id', differs: true, text: "- 123\n+ 456")],
            passthru: true,
        );

        $this->assertMatchesGolden('expectation-call-mismatch-passthru', $exception->getMessage());
    }

    public function test_renders_unsatisfied_expectation_on_a_fabricated_double(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'save',
            description: 'expected `save(any arguments)` to be called exactly 1 time, but it was never called',
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: [],
        );
        $exception = new UnsatisfiedExpectationException('SecondLink', [$expectation], fabricated: true);

        $this->assertMatchesGolden('unsatisfied-expectation-fabricated', $exception->getMessage());
    }

    public function test_renders_call_limit_exceeded_on_a_fabricated_double(): void
    {
        $exception = new ExpectationCallLimitExceededException('SecondLink', 'delete', '1', 1, 2, fabricated: true);

        $this->assertMatchesGolden('call-limit-exceeded-fabricated', $exception->getMessage());
    }

    /**
     * The argument-comparison block has to compose correctly with the
     * fabricated-double note appended after it —
     * DoubleException::appendFabricatedNote() rtrims trailing newlines
     * before appending, and the block always ends in one (see
     * CallListFormatter::renderComparisonBlock()) — so this is the one
     * case that could plausibly mangle the boundary between them.
     */
    public function test_renders_unsatisfied_expectation_with_argument_comparisons_on_a_fabricated_double(): void
    {
        $expectation = new UnsatisfiedExpectation(
            method: 'save',
            description: "expected `save('baz')` to be called exactly 1 time, but it was never called",
            expectedMin: 1,
            expectedMax: 1,
            timesCalled: 0,
            otherObservedCalls: ["'Baz'"],
            argumentComparisons: [
                new ArgumentComparison(label: 'value', differs: true, text: "- 'baz'\n+ 'Baz'"),
            ],
        );
        $exception = new UnsatisfiedExpectationException('SecondLink', [$expectation], fabricated: true);

        $this->assertMatchesGolden('unsatisfied-expectation-with-argument-comparisons-fabricated', $exception->getMessage());
    }

    public function test_renders_fabrication_limit_exceeded(): void
    {
        $exception = new FabricationLimitExceededException('SecondLink', 'toThird', 'ThirdLink', 1);

        $this->assertMatchesGolden('fabrication-limit-exceeded', $exception->getMessage());
    }

    public function test_renders_unknown_method_on_a_fabricated_double(): void
    {
        $exception = new UnknownMethodException('BookRepositoryInterface', 'bogus', fabricated: true);

        $this->assertMatchesGolden('unknown-method-fabricated', $exception->getMessage());
    }

    public function test_renders_unknown_method_on_a_fabricated_double_with_a_suggestion(): void
    {
        $exception = new UnknownMethodException('BookRepositoryInterface', 'sav', fabricated: true, suggestion: 'save');

        $this->assertMatchesGolden('unknown-method-fabricated-with-suggestion', $exception->getMessage());
    }

    public function test_renders_mode_configuration_on_a_fabricated_double(): void
    {
        $exception = new ModeConfigurationException('SecondLink', 'Strict', 'Strict', fabricated: true);

        $this->assertMatchesGolden('mode-configuration-fabricated', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_must_be_interface(): void
    {
        $exception = InvalidDoubleTargetException::mustBeInterface('Book');

        $this->assertMatchesGolden('invalid-double-target-must-be-interface', $exception->getMessage());
    }

    public function test_renders_invalid_double_target_duplicate_target(): void
    {
        $exception = InvalidDoubleTargetException::duplicateTarget('LoggerInterface');

        $this->assertMatchesGolden('invalid-double-target-duplicate-target', $exception->getMessage());
    }

    public function test_renders_static_method_on_a_fabricated_double(): void
    {
        $exception = new StaticMethodException('HasStaticMethod', 'make', fabricated: true);

        $this->assertMatchesGolden('static-method-fabricated', $exception->getMessage());
    }

    public function test_renders_magic_method_on_a_fabricated_double(): void
    {
        $exception = new MagicMethodException('HasMagicMethod', '__call', fabricated: true);

        $this->assertMatchesGolden('magic-method-fabricated', $exception->getMessage());
    }

    public function test_renders_out_of_order_call_on_a_fabricated_double(): void
    {
        $exception = new OutOfOrderCallException('SecondLink', 'open', 'close', fabricated: true);

        $this->assertMatchesGolden('out-of-order-call-fabricated', $exception->getMessage());
    }

    public function test_renders_unsatisfied_received_assertion(): void
    {
        $exception = new UnsatisfiedReceivedAssertionException(
            'BookRepository',
            'expected `delete(any arguments)` to be called at least 1 time, but it was never called',
        );

        $this->assertMatchesGolden('unsatisfied-received-assertion', $exception->getMessage());
    }

    public function test_renders_unsatisfied_received_assertion_on_a_fabricated_double(): void
    {
        $exception = new UnsatisfiedReceivedAssertionException(
            'SecondLink',
            'expected `save(any arguments)` to never be called, but it was called 1 time',
            fabricated: true,
        );

        $this->assertMatchesGolden('unsatisfied-received-assertion-fabricated', $exception->getMessage());
    }

    /**
     * The motivating scenario, mirrored from
     * test_renders_unsatisfied_expectation_with_call_correlation: a
     * received('event')->with(...) assertion fails on argument mismatch, even
     * though the method really was called — just with something else. Diffed
     * against that one recorded call, the same way the verify()-time and
     * strict-mode paths are.
     */
    public function test_renders_unsatisfied_received_assertion_with_call_correlation(): void
    {
        $expected = 'this text does not match what was actually logged';
        $actual = 'found usages of renamed pagination methods';

        $exception = new UnsatisfiedReceivedAssertionException(
            'AnalyticsHelper',
            sprintf('expected `event(%s)` to be called at least 1 time, but it was called 0 times', var_export($expected, true)),
            method: 'event',
            otherObservedCalls: [var_export($actual, true)],
            argumentComparisons: [
                new ArgumentComparison(label: 'message', differs: true, text: StringDiffer::diff($expected, $actual)),
            ],
        );

        $this->assertMatchesGolden('unsatisfied-received-assertion-with-correlation', $exception->getMessage());
    }

    public function test_renders_unsatisfied_received_assertion_with_capped_call_correlation(): void
    {
        $exception = new UnsatisfiedReceivedAssertionException(
            'AnalyticsHelper',
            'expected `event(6)` to be called at least 1 time, but it was called 0 times',
            method: 'event',
            otherObservedCalls: ['1', '2', '3', '4'],
        );

        $this->assertMatchesGolden('unsatisfied-received-assertion-with-correlation-capped', $exception->getMessage());
    }

    public function test_renders_unused_assertion(): void
    {
        $exception = new UnusedAssertionException('Logger', ["info('hello')"]);

        $this->assertMatchesGolden('unused-assertion', $exception->getMessage());
    }

    public function test_renders_unused_assertion_across_multiple_methods(): void
    {
        $exception = new UnusedAssertionException('Logger', ["info('hello')", "error('uh oh')"]);

        $this->assertMatchesGolden('unused-assertion-multiple-methods', $exception->getMessage());
    }

    public function test_renders_unused_assertion_on_a_fabricated_double(): void
    {
        $exception = new UnusedAssertionException('SecondLink', ['open()'], fabricated: true);

        $this->assertMatchesGolden('unused-assertion-fabricated', $exception->getMessage());
    }

    public function test_renders_unused_assertion_with_capped_call_list(): void
    {
        $exception = new UnusedAssertionException('AnalyticsHelper', ['event(1)', 'event(2)', 'event(3)', 'event(4)']);

        $this->assertMatchesGolden('unused-assertion-capped', $exception->getMessage());
    }

    public function test_renders_reserved_name_collision_for_a_single_method(): void
    {
        $exception = ReservedNameCollisionException::forCollisions('ExpectsCollisionInterface', ['expects']);

        $this->assertMatchesGolden('reserved-name-collision-single', $exception->getMessage());
    }

    public function test_renders_reserved_name_collision_for_multiple_methods(): void
    {
        $exception = ReservedNameCollisionException::forCollisions('Fillable&Sized', ['expects', 'verify']);

        $this->assertMatchesGolden('reserved-name-collision-multiple', $exception->getMessage());
    }
}
