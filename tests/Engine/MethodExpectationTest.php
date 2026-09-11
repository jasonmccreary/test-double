<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Engine;

use JMac\Testing\Engine\MethodExpectation;
use JMac\Testing\Matching\Argument;
use JMac\Testing\Tests\Support\Book;
use PHPUnit\Framework\TestCase;

final class MethodExpectationTest extends TestCase
{
    public function test_required_expectation_defaults_to_exactly_once(): void
    {
        $expectation = new MethodExpectation('find', required: true);

        $this->assertFalse($expectation->isSatisfied());

        $expectation->recordMatch();

        $this->assertTrue($expectation->isSatisfied());
        $this->assertFalse($expectation->exceedsMaximum());

        $expectation->recordMatch();

        $this->assertTrue($expectation->exceedsMaximum());
    }

    public function test_optional_expectation_defaults_to_any_number_including_zero(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->assertTrue($expectation->isSatisfied());
        $this->assertFalse($expectation->exceedsMaximum());

        for ($i = 0; $i < 50; $i++) {
            $expectation->recordMatch();
        }

        $this->assertFalse($expectation->exceedsMaximum());
    }

    public function test_with_omitted_matches_any_arguments(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->assertTrue($expectation->matchesArguments([1]));
        $this->assertTrue($expectation->matchesArguments([1, 2, 3]));
        $this->assertTrue($expectation->matchesArguments([]));
    }

    public function test_with_constrains_to_matching_arguments_by_value_equality(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->with(1, 'two');

        $this->assertTrue($expectation->matchesArguments([1, 'two']));
        $this->assertFalse($expectation->matchesArguments([1, 'three']));
        $this->assertFalse($expectation->matchesArguments([1]));
    }

    public function test_with_remaining_constrains_only_the_leading_arguments(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->with(1, 2, Argument::remaining());

        $this->assertTrue($expectation->matchesArguments([1, 2]));
        $this->assertTrue($expectation->matchesArguments([1, 2, 3]));
        $this->assertTrue($expectation->matchesArguments([1, 2, 3, 4, 5]));
    }

    public function test_with_remaining_still_requires_the_leading_arguments_to_match(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->with(1, 2, Argument::remaining());

        $this->assertFalse($expectation->matchesArguments([1, 99, 3]));
        $this->assertFalse($expectation->matchesArguments([1]));
        $this->assertFalse($expectation->matchesArguments([]));
    }

    public function test_with_remaining_alone_matches_any_arguments_including_none(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->with(Argument::remaining());

        $this->assertTrue($expectation->matchesArguments([]));
        $this->assertTrue($expectation->matchesArguments([1, 2, 3]));
    }

    public function test_with_rejects_remaining_anywhere_but_last(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->with(Argument::remaining(), 2);
    }

    public function test_with_none_matches_only_a_zero_argument_call(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->with(Argument::none());

        $this->assertTrue($expectation->matchesArguments([]));
        $this->assertFalse($expectation->matchesArguments([1]));
        $this->assertFalse($expectation->matchesArguments([1, 2]));
    }

    public function test_with_rejects_none_combined_with_other_arguments(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->with(Argument::none(), 2);
    }

    /**
     * Unlike every other constraint, the predicate's own arity is what
     * decides how many arguments it cares about — a 2-parameter predicate
     * still matches a 3-argument call, since matchesArguments() bypasses
     * positional arity checking for this matcher entirely.
     */
    public function test_with_all_hands_the_whole_argument_list_to_the_predicate(): void
    {
        $expectation = (new MethodExpectation('broadcast', required: false))
            ->with(Argument::all(fn (array $channels, string $eventName): bool => $eventName === 'foo'));

        $this->assertTrue($expectation->matchesArguments([['a'], 'foo', ['payload' => true]]));
        $this->assertFalse($expectation->matchesArguments([['a'], 'bar', ['payload' => true]]));
    }

    public function test_with_rejects_all_combined_with_other_arguments(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->with(Argument::all(fn (): bool => true), 2);
    }

    public function test_compare_arguments_is_null_when_all_matches(): void
    {
        $expectation = (new MethodExpectation('find', required: true))
            ->with(Argument::all(fn (int $id): bool => $id > 0));

        $this->assertNull($expectation->compareArguments([1]));
    }

    /**
     * A joint mismatch isn't a positional diff — there's no single argument
     * index it belongs to — so it gets its own 'joint' kind instead of being
     * forced into a 'comparisons' entry that would misattribute the failure
     * to whichever position happened to be index 0.
     */
    public function test_compare_arguments_reports_a_joint_mismatch_by_its_own_kind(): void
    {
        $expectation = (new MethodExpectation('find', required: true))
            ->with(Argument::all(fn (int $id, string $name): bool => $id > 0 && $name !== ''));

        $this->assertSame(
            ['kind' => 'joint', 'text' => "arguments did not jointly satisfy predicate: (0, 'taylor')"],
            $expectation->compareArguments([0, 'taylor']),
        );
    }

    public function test_describe_renders_none_as_no_arguments(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with(Argument::none());

        $this->assertSame(
            'expected `find(no arguments)` to be called exactly 1 time, but it was never called',
            $expectation->describe(),
        );
    }

    public function test_describe_renders_all_as_all_ellipsis(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with(Argument::all(fn (): bool => true));

        $this->assertSame(
            'expected `find(all(...))` to be called exactly 1 time, but it was never called',
            $expectation->describe(),
        );
    }

    public function test_describe_renders_remaining_as_an_ellipsis(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with(1, 2, Argument::remaining());

        $this->assertSame(
            'expected `find(1, 2, ...)` to be called exactly 1 time, but it was never called',
            $expectation->describe(),
        );
    }

    public function test_never_sets_maximum_to_zero(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->never();

        $expectation->recordMatch();

        $this->assertTrue($expectation->exceedsMaximum());
    }

    public function test_times_sets_an_exact_required_count(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(2);

        $expectation->recordMatch();
        $this->assertFalse($expectation->isSatisfied());

        $expectation->recordMatch();
        $this->assertTrue($expectation->isSatisfied());
        $this->assertFalse($expectation->exceedsMaximum());

        $expectation->recordMatch();
        $this->assertTrue($expectation->exceedsMaximum());
    }

    public function test_times_with_two_positional_arguments_sets_a_between_range(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(1, 3);

        $this->assertSame(1, $expectation->minimumCalls());
        $this->assertSame(3, $expectation->maximumCalls());
    }

    public function test_times_with_named_minimum_sets_an_open_ended_lower_bound(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(minimum: 2);

        $expectation->recordMatch();
        $this->assertFalse($expectation->isSatisfied());

        $expectation->recordMatch();
        $this->assertTrue($expectation->isSatisfied());

        for ($i = 0; $i < 50; $i++) {
            $expectation->recordMatch();
        }
        $this->assertFalse($expectation->exceedsMaximum());
    }

    public function test_times_with_named_maximum_sets_a_zero_floor(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(maximum: 2);

        $this->assertTrue($expectation->isSatisfied());

        $expectation->recordMatch();
        $expectation->recordMatch();
        $this->assertFalse($expectation->exceedsMaximum());

        $expectation->recordMatch();
        $this->assertTrue($expectation->exceedsMaximum());
    }

    public function test_times_with_named_minimum_and_maximum_matches_the_positional_between_form(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(minimum: 1, maximum: 3);

        $this->assertSame(1, $expectation->minimumCalls());
        $this->assertSame(3, $expectation->maximumCalls());
    }

    public function test_times_rejects_a_positional_count_combined_with_a_named_minimum(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->times(3, minimum: 1);
    }

    public function test_times_rejects_being_called_with_no_bounds_at_all(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->times();
    }

    public function test_times_rejects_a_minimum_greater_than_the_maximum(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->times(minimum: 5, maximum: 2);
    }

    public function test_resolve_return_holds_at_the_last_value_once_sequential_returns_are_exhausted(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->returns('a', 'b');

        $expectation->recordMatch();
        $this->assertSame('a', $expectation->resolveReturn([]));

        $expectation->recordMatch();
        $this->assertSame('b', $expectation->resolveReturn([]));

        $expectation->recordMatch();
        $this->assertSame('b', $expectation->resolveReturn([]));
    }

    public function test_resolve_return_throws_the_configured_exception(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->throws(new \RuntimeException('boom'));

        $expectation->recordMatch();

        $this->expectExceptionMessage('boom');

        $expectation->resolveReturn([]);
    }

    public function test_resolve_return_throws_sequential_exceptions_holding_at_the_last_once_exhausted(): void
    {
        $first = new \RuntimeException('first failure');
        $second = new \LogicException('second failure');
        $expectation = (new MethodExpectation('find', required: false))->throws($first, $second);

        $expectation->recordMatch();
        try {
            $expectation->resolveReturn([]);
            $this->fail('Expected the first exception to be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($first, $exception);
        }

        $expectation->recordMatch();
        try {
            $expectation->resolveReturn([]);
            $this->fail('Expected the second exception to be thrown.');
        } catch (\LogicException $exception) {
            $this->assertSame($second, $exception);
        }

        // Holds at the last exception once the sequence is exhausted, same
        // as returns()'s own sequential-value behavior.
        $expectation->recordMatch();
        try {
            $expectation->resolveReturn([]);
            $this->fail('Expected the second exception to be thrown again.');
        } catch (\LogicException $exception) {
            $this->assertSame($second, $exception);
        }
    }

    public function test_throws_requires_at_least_one_exception(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->expectException(\InvalidArgumentException::class);

        $expectation->throws();
    }

    public function test_throws_replaces_a_previously_configured_return(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->returns('a');

        $expectation->throws(new \RuntimeException('boom'));
        $expectation->recordMatch();

        $this->expectExceptionMessage('boom');

        $expectation->resolveReturn([]);
    }

    public function test_resolve_return_using_passes_the_actual_call_arguments_to_the_resolver(): void
    {
        $expectation = (new MethodExpectation('find', required: false))
            ->resolves(fn (int $id): string => "id-{$id}");

        $expectation->recordMatch();

        $this->assertSame('id-7', $expectation->resolveReturn([7]));
    }

    public function test_has_return_configured_is_false_until_an_outcome_is_set(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->assertFalse($expectation->hasReturnConfigured());

        $expectation->returns('x');

        $this->assertTrue($expectation->hasReturnConfigured());
    }

    public function test_describe_renders_arguments_and_expected_count(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with(123);

        $this->assertSame('expected `find(123)` to be called exactly 1 time, but it was never called', $expectation->describe());
    }

    public function test_describe_renders_at_most_for_a_zero_floor_maximum(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(maximum: 3);

        $this->assertSame('expected `find(any arguments)` to be called at most 3 times, but it was never called', $expectation->describe());
    }

    public function test_describe_renders_between_for_a_two_sided_range(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->times(1, 3);

        $this->assertSame('expected `find(any arguments)` to be called between 1 and 3 times, but it was never called', $expectation->describe());
    }

    public function test_describe_arguments_renders_just_the_argument_pattern(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with(123);

        $this->assertSame('123', $expectation->describeArguments());
    }

    public function test_describe_arguments_renders_any_arguments_when_with_was_never_called(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->assertSame('any arguments', $expectation->describeArguments());
    }

    public function test_is_required_reflects_expects_versus_allows(): void
    {
        $this->assertTrue((new MethodExpectation('find', required: true))->isRequired());
        $this->assertFalse((new MethodExpectation('find', required: false))->isRequired());
    }

    public function test_with_accepts_a_matcher_alongside_bare_literals(): void
    {
        $expectation = (new MethodExpectation('find', required: false))
            ->with(Argument::any(), 'two');

        $this->assertTrue($expectation->matchesArguments([1, 'two']));
        $this->assertTrue($expectation->matchesArguments(['anything', 'two']));
        $this->assertFalse($expectation->matchesArguments([1, 'three']));
    }

    public function test_with_type_matcher_constrains_to_instances_of_the_given_class(): void
    {
        $expectation = (new MethodExpectation('save', required: false))
            ->with(Argument::type(Book::class));

        $this->assertTrue($expectation->matchesArguments([new Book('Some Title')]));
        $this->assertFalse($expectation->matchesArguments(['not a book']));
    }

    public function test_with_predicate_matcher_constrains_by_a_callable(): void
    {
        $expectation = (new MethodExpectation('find', required: false))
            ->with(Argument::satisfies(fn (int $id): bool => $id > 100));

        $this->assertTrue($expectation->matchesArguments([101]));
        $this->assertFalse($expectation->matchesArguments([100]));
    }

    public function test_describe_renders_matcher_descriptions(): void
    {
        $expectation = (new MethodExpectation('find', required: true))
            ->with(Argument::any());

        $this->assertSame('expected `find(any())` to be called exactly 1 time, but it was never called', $expectation->describe());
    }

    public function test_is_ordered_is_false_until_in_order_is_called(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        $this->assertFalse($expectation->isOrdered());

        $expectation->ordered();

        $this->assertTrue($expectation->isOrdered());
    }

    public function test_in_order_is_chainable(): void
    {
        $expectation = (new MethodExpectation('find', required: false))->ordered();

        $this->assertInstanceOf(MethodExpectation::class, $expectation);
        $this->assertTrue($expectation->isOrdered());
    }

    public function test_compare_arguments_is_null_when_with_was_never_called(): void
    {
        $expectation = new MethodExpectation('find', required: true);

        $this->assertNull($expectation->compareArguments(['whatever']));
    }

    public function test_compare_arguments_is_null_when_the_arguments_actually_match(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with('baz');

        $this->assertNull($expectation->compareArguments(['baz']));
    }

    public function test_compare_arguments_reports_a_single_differing_argument(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with('baz');

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- 'baz'\n+ 'Baz'"],
                ],
            ],
            $expectation->compareArguments(['Baz']),
        );
    }

    /**
     * Every differing argument gets its own comparison entry now — unlike
     * the single-argument-only diff this replaced, two or more differing
     * values is no longer collapsed away, since the labeled block format
     * this feeds doesn't get noisy the way a flat semicolon line did.
     */
    public function test_compare_arguments_reports_every_differing_argument(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with('baz', 5);

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- 'baz'\n+ 'Baz'"],
                    ['index' => 1, 'differs' => true, 'text' => "- 5\n+ 6"],
                ],
            ],
            $expectation->compareArguments(['Baz', 6]),
        );
    }

    /**
     * A matching argument still gets a comparison entry — just a non-diff
     * one carrying its own value — so the caller can show the whole actual
     * call, not just whichever positions happened to differ.
     */
    public function test_compare_arguments_includes_matching_arguments_as_non_diff_context(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with('baz', 5);

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => false, 'text' => "'baz'"],
                    ['index' => 1, 'differs' => true, 'text' => "- 5\n+ 6"],
                ],
            ],
            $expectation->compareArguments(['baz', 6]),
        );
    }

    public function test_compare_arguments_reports_an_argument_count_mismatch_as_a_count_not_a_position(): void
    {
        $expectation = (new MethodExpectation('find', required: true))->with('baz', 5);

        $this->assertSame(
            ['kind' => 'arity', 'text' => 'expected 2 arguments, got 1 argument'],
            $expectation->compareArguments(['baz']),
        );
    }

    public function test_compare_arguments_reports_a_none_matcher_violation_by_count(): void
    {
        $expectation = (new MethodExpectation('reset', required: true))->with(Argument::none());

        $this->assertSame(
            ['kind' => 'arity', 'text' => 'expected no arguments, got 1 argument'],
            $expectation->compareArguments(['unexpected']),
        );
    }

    public function test_compare_arguments_pairs_a_type_matchers_own_description_against_the_actual_value(): void
    {
        $expectation = (new MethodExpectation('save', required: true))->with(Argument::type(Book::class));

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => '- type('.Book::class.")\n+ 'not a book'"],
                ],
            ],
            $expectation->compareArguments(['not a book']),
        );
    }

    /**
     * The headline case: a single differing string argument long enough
     * that dumping both values in full would bury the one-character typo —
     * StringDiffer::MIN_LENGTH_TO_DIFF is the threshold.
     */
    public function test_compare_arguments_diffs_a_long_string_argument(): void
    {
        $expected = str_repeat('a', 30).'baz'.str_repeat('a', 30);
        $actual = str_repeat('a', 30).'BAZ'.str_repeat('a', 30);

        $expectation = (new MethodExpectation('render', required: true))->with($expected);

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- '…aaaaaaaaaaaabazaaaaaaaaaaaa…'\n+ '…aaaaaaaaaaaaBAZaaaaaaaaaaaa…'"],
                ],
            ],
            $expectation->compareArguments([$actual]),
        );
    }

    /**
     * Two arrays with the same element count but different values both
     * describe() as "array(2)" — without a fallback, the diff would show
     * "- array(2)\n+ array(2)", which is no diagnostic help at all.
     */
    public function test_compare_arguments_dumps_arrays_whose_short_descriptions_collide(): void
    {
        $expected = ['account_id' => 1, 'name' => 'taylor'];
        $actual = ['account_id' => '1', 'name' => 'taylor'];

        $expectation = (new MethodExpectation('getCount', required: true))->with($expected);

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => sprintf("- %s\n+ %s", var_export($expected, true), var_export($actual, true))],
                ],
            ],
            $expectation->compareArguments([$actual]),
        );
    }

    /**
     * A differing element count already reads clearly as "array(2)" vs.
     * "array(3)" — no need for the full dump when the short form isn't
     * ambiguous.
     */
    public function test_compare_arguments_does_not_dump_arrays_whose_short_descriptions_differ(): void
    {
        $expected = ['a' => 1, 'b' => 2];
        $actual = ['a' => 1, 'b' => 2, 'c' => 3];

        $expectation = (new MethodExpectation('getCount', required: true))->with($expected);

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- array(2)\n+ array(3)"],
                ],
            ],
            $expectation->compareArguments([$actual]),
        );
    }

    public function test_compare_arguments_does_not_diff_a_non_equals_matcher_even_when_long(): void
    {
        $long = str_repeat('a', 60);
        $expectation = (new MethodExpectation('find', required: true))
            ->with(Argument::satisfies(fn (string $value): bool => $value === $long));

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- satisfies(...)\n+ ".var_export('not it at all, and also quite long indeed', true)],
                ],
            ],
            $expectation->compareArguments(['not it at all, and also quite long indeed']),
        );
    }

    /**
     * Argument::remaining()'s trailing arguments are unconstrained by
     * definition, so they're always context (never a diff) — but they
     * still appear, so the caller sees the whole actual call rather than
     * just its constrained prefix.
     */
    public function test_compare_arguments_includes_remaining_matcher_arguments_as_context(): void
    {
        $expectation = (new MethodExpectation('combine', required: true))->with('-', Argument::remaining());

        $this->assertSame(
            [
                'kind' => 'comparisons',
                'comparisons' => [
                    ['index' => 0, 'differs' => true, 'text' => "- '-'\n+ '+'"],
                    ['index' => 1, 'differs' => false, 'text' => "'a'"],
                    ['index' => 2, 'differs' => false, 'text' => "'b'"],
                ],
            ],
            $expectation->compareArguments(['+', 'a', 'b']),
        );
    }
}
