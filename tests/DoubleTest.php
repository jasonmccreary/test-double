<?php

declare(strict_types=1);

namespace JMac\Testing\Tests;

use JMac\Testing\CheckEvent;
use JMac\Testing\Double;
use JMac\Testing\Engine\ExceptionFactory;
use JMac\Testing\Engine\ReceivedAssertion;
use JMac\Testing\Exceptions\MagicMethodException;
use JMac\Testing\Exceptions\ModeConfigurationException;
use JMac\Testing\Exceptions\StaticMethodException;
use JMac\Testing\Exceptions\UnknownMethodException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitExpectationCallLimitExceededException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitExpectationCallMismatchException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitOutOfOrderCallException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitUnexpectedCallException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitUnsatisfiedExpectationException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitUnsatisfiedReceivedAssertionException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitUnusedAssertionException;
use JMac\Testing\Matching\Argument;
use JMac\Testing\Tests\Support\Book;
use JMac\Testing\Tests\Support\BookRepositoryInterface;
use JMac\Testing\Tests\Support\Fillable;
use JMac\Testing\Tests\Support\FinalLogger;
use JMac\Testing\Tests\Support\HasInvokeMethod;
use JMac\Testing\Tests\Support\HasMagicMethod;
use JMac\Testing\Tests\Support\HasStaticMethod;
use JMac\Testing\Tests\Support\ReadOnlyLogger;
use JMac\Testing\Tests\Support\Sized;
use JMac\Testing\Tests\Support\VariadicInterface;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DoubleTest extends TestCase
{
    /**
     * Deliberately a property, not a local variable, for the
     * $pendingReceived tests below: PHP tears down a method's own locals
     * the instant that method returns — before verifyAll() could ever be
     * invoked from a separate #[After] hook the way VerifiesDoubles really
     * uses it — so a local variable can't actually reproduce the gap
     * $pendingReceived closes. A property on $this survives past the test
     * method's own return (this TestCase instance isn't destroyed until
     * well after its own #[After] hooks would run), which is what makes
     * these tests a real reproduction instead of a false positive.
     */
    private ?ReceivedAssertion $heldAssertion = null;

    /**
     * Double::$listeners is a process-lifetime registry, not reset per test
     * the way $pending/$pendingReceived are (see Double::listen()'s own
     * docblock) — without this, a listener registered in one test method
     * here would still be live, and get notified, for every test after it.
     */
    #[After]
    final public function clearCheckEventListeners(): void
    {
        Double::clearListeners();
    }

    public function test_for_returns_an_instance_of_the_target(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->assertInstanceOf(BookRepositoryInterface::class, $double);
    }

    /**
     * The end-to-end proof that Double::bypassFinals() actually works: a
     * target that would otherwise be rejected by
     * InvalidDoubleTargetException::isFinal() (see
     * ClassGeneratorTest::test_rejects_a_final_class()) is doubled,
     * configured, and verified normally once bypassing is enabled first.
     *
     * #[RunInSeparateProcess] isn't incidental — it's required. Enabling the
     * bypass replaces PHP's file:// stream wrapper for the rest of the
     * process, and FinalLogger must not have been loaded yet for the
     * rewrite to have anything to intercept (see FinalBypass's own docblock
     * on the ordering requirement). Both conditions only hold in a process
     * dedicated to this one test.
     */
    #[RunInSeparateProcess]
    public function test_bypass_finals_allows_doubling_a_final_class(): void
    {
        Double::bypassFinals();

        $double = Double::for(FinalLogger::class);
        $double->expects('log')->with('hello')->returns(true);

        $this->assertInstanceOf(FinalLogger::class, $double);
        $this->assertTrue($double->log('hello'));

        $double->verify();
    }

    /**
     * A readonly class's properties must stay readonly all the way down its
     * subclass chain — ClassGenerator marks the generated double readonly
     * too whenever the target is, so this works rather than crashing (see
     * ClassGeneratorTest::test_generates_a_readonly_subclass_of_a_readonly_class()
     * for the failure this previously produced).
     */
    public function test_for_returns_an_instance_of_a_readonly_target(): void
    {
        $double = Double::for(ReadOnlyLogger::class);
        $double->allows('log')->returns(true);

        $this->assertInstanceOf(ReadOnlyLogger::class, $double);
        $this->assertTrue($double->log('hello'));
    }

    public function test_for_with_multiple_targets_returns_a_double_satisfying_all_of_them(): void
    {
        $double = Double::for(Fillable::class, Sized::class);

        $this->assertInstanceOf(Fillable::class, $double);
        $this->assertInstanceOf(Sized::class, $double);
    }

    public function test_for_with_multiple_targets_configures_methods_declared_on_either_one(): void
    {
        $double = Double::for(Fillable::class, Sized::class);

        $double->allows('fill')->returns(true);
        $double->allows('size')->returns(3);

        $this->assertTrue($double->fill());
        $this->assertSame(3, $double->size());
    }

    public function test_for_with_multiple_targets_uses_a_combined_short_label_in_messages(): void
    {
        $double = Double::for(Fillable::class, Sized::class);
        $double->expects('fill')->returns(true);

        // Regression check: label derivation used to take the short name of
        // the whole "&"-joined string in one pass, which silently dropped
        // every candidate but the last (see Double::deriveLabel()) —
        // this double's label would have rendered as just "Sized".
        $this->expectException(PHPUnitUnsatisfiedExpectationException::class);
        $this->expectExceptionMessage('Fillable&Sized');

        $double->verify();
    }

    public function test_for_with_no_targets_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Double::for();
    }

    public function test_allows_configures_a_return_value_for_a_matching_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $book = new Book('Dune');

        $double->allows('find')->with(1)->returns($book);

        $this->assertSame($book, $double->find(1));
    }

    public function test_allows_may_be_called_any_number_of_times_including_zero(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('save')->returns(true);

        $double->verify();

        $this->assertTrue($double->save(new Book('Dune')));
        $this->assertTrue($double->save(new Book('Dune Messiah')));

        $double->verify();
    }

    public function test_expects_defaults_to_exactly_once(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        $double->delete(1);

        $double->verify();
    }

    public function test_expects_fails_verify_when_never_called(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        $this->expectException(PHPUnitUnsatisfiedExpectationException::class);
        $this->expectExceptionMessageMatches('/expected `delete\(any arguments\)` to be called exactly 1 time, but it was never called/s');

        $double->verify();
    }

    public function test_expects_throws_when_called_more_times_than_allowed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        $double->delete(1);

        $this->expectException(PHPUnitExpectationCallLimitExceededException::class);

        $double->delete(1);
    }

    public function test_last_registered_matching_expectation_wins(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $default = new Book('Default');
        $specific = new Book('Specific');

        $double->allows('find')->returns($default);
        $double->allows('find')->with(123)->returns($specific);

        $this->assertSame($specific, $double->find(123));
        $this->assertSame($default, $double->find(456));
    }

    public function test_a_generic_catch_all_no_longer_starves_an_earlier_specific_expectation_once_exhausted(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $specific = new Book('Specific');

        $double->expects('find')->with(1)->returns($specific);
        $double->expects('find'); // unconstrained catch-all, registered last

        // First call: the last-registered, unconstrained expectation still
        // has room, so it wins, same as before this fix.
        $double->find(1);

        // Second call: the catch-all's own times() budget (default: exactly
        // once) is already spent, so matching now falls through to the
        // earlier, still-unconsumed with(1) expectation instead of
        // re-selecting the exhausted catch-all and throwing
        // expectationCallLimitExceeded() for a call it was never meant to
        // serve.
        $this->assertSame($specific, $double->find(1));

        $double->verify();
    }

    public function test_stacking_separate_expectations_for_the_same_method_and_args_no_longer_throws(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $first = new Book('First');
        $second = new Book('Second');

        // The direct Mockery habit: two separate expectation objects for
        // the same method+args, meant to be consumed one after another.
        // This used to throw on the second call, since matching always
        // re-picked the same (already-exhausted) most-recently-registered
        // expectation instead of falling through to the one registered
        // before it. `times()->returns(...)` (see
        // test_sequential_returns_hold_at_the_last_value_on_further_calls())
        // stays the documented idiom for this — note that registration
        // order isn't preserved as call order here: the more-recently
        // registered expectation still wins first, for as long as it has
        // room.
        $double->expects('find')->with(1)->returns($first);
        $double->expects('find')->with(1)->returns($second);

        $this->assertSame($second, $double->find(1));
        $this->assertSame($first, $double->find(1));

        $double->verify();
    }

    /**
     * Failure mode 1a's diagnostics improvement: once every matching
     * expectation for a method+args is genuinely exhausted, the "exceeds
     * maximum" error names how many *other* expectations also matched but
     * weren't the one selected — the fact that made this failure mode take a
     * source-level read of registration order to diagnose in the first
     * place.
     */
    public function test_call_limit_exceeded_names_other_matching_expectations_left_unselected(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('find')->with(1)->returns(new Book('First'));
        $double->expects('find')->with(1)->returns(new Book('Second'));

        $double->find(1);
        $double->find(1);

        try {
            $double->find(1);
            $this->fail('Expected PHPUnitExpectationCallLimitExceededException to be thrown.');
        } catch (PHPUnitExpectationCallLimitExceededException $exception) {
            $this->assertStringContainsString(
                "Note: 1 other expectation for `find` also matches this call's arguments but was not selected — check registration order.",
                $exception->getMessage(),
            );
        }
    }

    public function test_in_order_calls_made_in_declared_order_succeed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->ordered();
        $double->allows('save')->ordered();
        $double->allows('delete')->ordered();

        $double->find(1);
        $double->save(new Book('Dune'));
        $double->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_in_order_calls_out_of_declared_order_throw_immediately(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->ordered();
        $double->allows('save')->ordered();

        $double->save(new Book('Dune'));

        $this->expectException(PHPUnitOutOfOrderCallException::class);
        $this->expectExceptionMessage('received `find()` out of order. Using `ordered`, this was expected to be called before `save()` was called.');

        // find() is earlier in the declared sequence than save(), which
        // already happened — calling it now is a regression.
        $double->find(1);
    }

    public function test_in_order_ignores_calls_to_expectations_not_themselves_marked_in_order(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->ordered();
        $double->allows('delete')->ordered();
        $double->allows('save')->returns(true); // not ordered

        $double->find(1);
        $double->save(new Book('Dune')); // unordered — freely interleaved
        $double->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_in_order_allows_skipping_ahead_without_every_step_occurring(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->ordered();
        $double->allows('save')->ordered();
        $double->allows('delete')->ordered();

        // save() (the middle step) never happens — jumping straight from
        // find() to delete() is a forward skip, not a regression, and is
        // allowed (mirrors Mockery's own validateOrder()). A skipped required
        // step still surfaces separately, via the ordinary
        // unmet-expectation check at verify() time.
        $double->find(1);
        $double->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_in_order_is_scoped_per_double_not_across_doubles(): void
    {
        $first = Double::for(BookRepositoryInterface::class);
        $second = Double::for(BookRepositoryInterface::class);

        $first->allows('find')->ordered();
        $first->allows('save')->ordered();
        $second->allows('delete')->ordered();
        $second->allows('count')->ordered();

        // Interleaved across two doubles — each double's own declared
        // sequence is independent, so this is a violation on neither.
        $second->delete(1);
        $first->find(1);
        $second->count();
        $first->save(new Book('Dune'));

        $this->addToAssertionCount(1);
    }

    public function test_in_order_works_with_expects_as_well_as_allows(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('find')->returns(null)->ordered();
        $double->expects('save')->returns(true)->ordered();

        $double->find(1);
        $double->save(new Book('Dune'));

        $double->verify();
    }

    public function test_sequential_returns_hold_at_the_last_value_on_further_calls(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $first = new Book('First');
        $second = new Book('Second');

        $double->allows('find')->returns($first, $second);

        $this->assertSame($first, $double->find(1));
        $this->assertSame($second, $double->find(1));
        $this->assertSame($second, $double->find(1));
    }

    public function test_throws_configures_an_exception_to_be_thrown(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $exception = new \OutOfBoundsException('not found');

        $double->allows('find')->with(999)->throws($exception);

        $this->expectExceptionObject($exception);

        $double->find(999);
    }

    public function test_sequential_throws_hold_at_the_last_exception_on_further_calls(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $first = new \OutOfBoundsException('first call fails');
        $second = new \RuntimeException('second call fails');

        $double->allows('find')->throws($first, $second);

        try {
            $double->find(1);
            $this->fail('Expected the first exception to be thrown.');
        } catch (\OutOfBoundsException $exception) {
            $this->assertSame($first, $exception);
        }

        try {
            $double->find(1);
            $this->fail('Expected the second exception to be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($second, $exception);
        }

        try {
            $double->find(1);
            $this->fail('Expected the second exception to be thrown again.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($second, $exception);
        }
    }

    public function test_resolves_computes_the_value_from_the_actual_arguments(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->allows('find')->resolves(fn (int $id): Book => new Book("Book #{$id}"));

        $this->assertSame('Book #42', $double->find(42)->title);
    }

    public function test_capture_writes_the_actual_argument_into_the_referenced_variable(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $captured = null;
        $dune = new Book('Dune');

        $double->allows('save')->with(Argument::capture($captured))->returns(true);

        $double->save($dune);

        $this->assertSame($dune, $captured);
    }

    public function test_capture_does_not_write_when_its_own_expectation_ends_up_not_matching(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $captured = 'sentinel';

        // Older — checked *after* the expectation below. The capture sits at
        // position 0 (would match anything), but position 1 requires a Book,
        // so this expectation still fails to match overall.
        $double->allows('find')->with(Argument::capture($captured), Argument::type(Book::class))->returns(null);
        // Newer — checked first, and fails on position 0 before ever reaching
        // position 1, so the loop falls through to the expectation above.
        $double->allows('find')->with(999, 'irrelevant')->returns(null);

        $this->assertNull($double->find(1, 'not a book')); // matches neither -> loose default

        $this->assertSame('sentinel', $captured); // unchanged - the capturing expectation never actually matched
    }

    public function test_type_matches_a_builtin_php_type_by_name_not_just_a_class(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $book = new Book('Dune');

        $double->allows('find')->with(Argument::type('int'))->returns($book);

        $this->assertSame($book, $double->find(42));
    }

    public function test_with_remaining_constrains_only_the_leading_arguments_end_to_end(): void
    {
        $double = Double::for(VariadicInterface::class);

        $double->allows('combine')->with('-', Argument::remaining())->returns('stubbed');

        $this->assertSame('stubbed', $double->combine('-', 'a'));
        $this->assertSame('stubbed', $double->combine('-', 'a', 'b', 'c'));
    }

    public function test_received_with_remaining_composes_the_same_way_as_expects(): void
    {
        $double = Double::for(VariadicInterface::class);

        $double->combine('-', 'a', 'b', 'c');

        $double->received('combine')->with('-', Argument::remaining());
    }

    /**
     * Argument::all()'s predicate sees the whole real call at once — here,
     * both the glue and every variadic part together — the same way
     * Mockery's withArgs(closure) used to, rather than one value per
     * position.
     */
    public function test_with_all_matches_against_the_whole_real_argument_list(): void
    {
        $double = Double::for(VariadicInterface::class);

        $double->allows('combine')
            ->with(Argument::all(fn (string $glue, string ...$parts): bool => $glue === '-' && count($parts) === 2))
            ->returns('stubbed');

        $this->assertSame('stubbed', $double->combine('-', 'a', 'b'));
    }

    public function test_with_all_call_mismatch_reports_the_joint_failure(): void
    {
        $double = Double::for(VariadicInterface::class);

        $double->expects('combine')
            ->with(Argument::all(fn (string $glue, string ...$parts): bool => $glue === '-'));

        $this->expectException(PHPUnitExpectationCallMismatchException::class);

        $double->combine('+', 'a', 'b');
    }

    public function test_never_forbids_any_call_at_all(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('delete')->never();

        $this->expectException(PHPUnitExpectationCallLimitExceededException::class);

        $double->delete(1);
    }

    public function test_at_least_once_is_satisfied_by_multiple_calls(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null)->times(minimum: 1);

        $double->delete(1);
        $double->delete(2);
        $double->delete(3);

        $double->verify();
    }

    public function test_times_requires_exactly_that_many_calls(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null)->times(2);

        $double->delete(1);
        $double->delete(2);

        $double->verify();
    }

    public function test_times_with_a_range_requires_a_call_count_within_bounds(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null)->times(1, 3);

        $double->delete(1);
        $double->delete(2);

        $double->verify();
    }

    public function test_times_with_a_named_maximum_fails_once_exceeded(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('delete')->returns(null)->times(maximum: 2);

        $double->delete(1);
        $double->delete(2);

        $this->expectException(PHPUnitExpectationCallLimitExceededException::class);

        $double->delete(3);
    }

    public function test_strict_mode_throws_immediately_on_an_unmatched_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();

        $this->expectException(PHPUnitUnexpectedCallException::class);

        $double->count();
    }

    /**
     * Strict mode's immediate rejection gets the same argument-by-argument
     * diff treatment as verify()'s never-satisfied-expectation path, and the
     * same trigger: exactly one other call already observed for this
     * method. The prior call's own arguments become the "expected" side —
     * not whatever's configured — so this works the same regardless of how
     * many (or how few) expects()/allows() are registered for `find`.
     */
    public function test_unexpected_call_diffs_against_the_one_other_observed_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();
        $double->allows('find')->with(123)->returns(new Book('Dune'));

        $double->find(123);

        try {
            $double->find(456);
            $this->fail('Expected PHPUnitUnexpectedCallException to be thrown.');
        } catch (PHPUnitUnexpectedCallException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('received an unexpected call to `find(456)`', $message);
            $this->assertStringContainsString("The following similar call was made to `find`:\n  id:\n    - 123\n    + 456", $message);
        }
    }

    /**
     * Symmetric extension to test_verify_failure_correlates_other_calls_observed_for_the_same_method()
     * above. Two other calls already observed leaves no fact-based way to
     * say which one this one "should" have resembled, so this falls back
     * to plain correlation instead of a diff — and still needs to prove
     * ProxyBehavior excludes the failing call itself (the one just
     * recorded) from that correlation list, not just that the field gets
     * populated with something.
     */
    public function test_unexpected_call_correlates_other_calls_already_observed_for_the_same_method(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();
        $double->allows('find')->with(123)->returns(new Book('Dune'));
        $double->allows('find')->with(789)->returns(new Book('Dune Messiah'));

        $double->find(123);
        $double->find(789);

        try {
            $double->find(456);
            $this->fail('Expected PHPUnitUnexpectedCallException to be thrown.');
        } catch (PHPUnitUnexpectedCallException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('received an unexpected call to `find(456)`', $message);
            $this->assertStringContainsString('The following calls to `find` were made during this test:', $message);
            $this->assertStringContainsString('find(123)', $message);
            // The failing call (456) must appear only once — in the "unexpected call to"
            // clause — and never inside the correlation list, which should only ever
            // contain calls that happened before this one.
            $this->assertSame(1, substr_count($message, 'find(456)'));
        }
    }

    /**
     * expects()'s per-method mismatch is a strictly more specific diagnosis
     * than Strict mode's own blanket "nothing was configured" rejection, so
     * it takes precedence even on a double that would have rejected this
     * call anyway.
     */
    public function test_expects_mismatch_takes_precedence_over_strict_modes_blanket_rejection(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();
        $double->expects('find')->with(123)->returns(new Book('Dune'));

        $this->expectException(PHPUnitExpectationCallMismatchException::class);

        $double->find(456);
    }

    public function test_mode_can_only_be_set_once(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();

        $this->expectException(ModeConfigurationException::class);

        $double->strict();
    }

    public function test_expects_rejects_an_undeclared_method_name(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(UnknownMethodException::class);
        $this->expectExceptionMessage('bogus');

        $double->expects('bogus');
    }

    public function test_expects_suggests_the_closest_declared_method_name_for_a_likely_typo(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(UnknownMethodException::class);
        $this->expectExceptionMessage('Did you mean `save`?');

        $double->expects('sav');
    }

    /**
     * "make" genuinely exists on HasStaticMethod, so this must fail with
     * StaticMethodException specifically, not UnknownMethodException — a
     * static method is a different problem (unconfigurable) from a typo
     * (nonexistent).
     */
    public function test_expects_rejects_a_static_method(): void
    {
        $double = Double::for(HasStaticMethod::class);

        $this->expectException(StaticMethodException::class);
        $this->expectExceptionMessage('static method');

        $double->expects('make');
    }

    /**
     * "__call" genuinely exists on HasMagicMethod, so this must fail
     * with MagicMethodException specifically, not UnknownMethodException —
     * same reasoning as the static-method case above, for a magic method
     * instead of a static one. __call is dynamic dispatch (see
     * ClassGenerator::DOUBLEABLE_MAGIC_METHODS), so it stays rejected —
     * unlike __invoke below.
     */
    public function test_expects_rejects_a_magic_method(): void
    {
        $double = Double::for(HasMagicMethod::class);

        $this->expectException(MagicMethodException::class);
        $this->expectExceptionMessage('magic method');

        $double->expects('__call');
    }

    /**
     * __invoke has a fixed, reflectable signature rather than PHP's
     * dynamic-dispatch magic (see ClassGenerator::DOUBLEABLE_MAGIC_METHODS),
     * so expects() configures it like any other method, and calling the
     * double as `$double(...)` runs the proxy instead of throwing.
     */
    public function test_expects_configures_invoke(): void
    {
        $double = Double::for(HasInvokeMethod::class);
        $double->expects('__invoke')->with(1)->returns('doubled');

        $this->assertSame('doubled', $double(1));
    }

    public function test_received_passes_when_the_method_was_called_at_least_once(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->delete(1);
        $double->received('delete');
    }

    public function test_received_fails_when_the_method_was_never_called(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);
        $this->expectExceptionMessageMatches('/expected `delete\(any arguments\)` to be called at least 1 time, but it was never called/s');

        $double->received('delete');
    }

    public function test_received_with_passes_when_a_matching_call_was_observed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $dune = new Book('Dune');

        $double->save($dune);
        $double->received('save')->with($dune);
    }

    public function test_received_with_fails_when_only_a_non_matching_call_was_observed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->save(new Book('Dune Messiah'));

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);

        $double->received('save')->with(new Book('Dune'));
    }

    /**
     * received()'s spy-style assertion gets the same argument-by-argument
     * diff treatment as expects()/allows()'s verify()-time path and strict
     * mode's immediate rejection — same trigger too: exactly one other call
     * already recorded for this method.
     */
    public function test_received_with_diffs_against_the_one_recorded_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(new Book('Dune'));

        $double->find(456);

        try {
            $double->received('find')->with(123)->check();
            $this->fail('Expected PHPUnitUnsatisfiedReceivedAssertionException to be thrown.');
        } catch (PHPUnitUnsatisfiedReceivedAssertionException $exception) {
            $this->assertStringContainsString("The following similar call was made to `find`:\n  id:\n    - 123\n    + 456", $exception->getMessage());
        }
    }

    /**
     * Two or more recorded calls leaves no fact-based way to say which one
     * this assertion was "supposed" to match, so this falls back to plain
     * correlation instead of a diff.
     */
    public function test_received_with_correlates_multiple_recorded_calls_without_diffing(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(new Book('Dune'));

        $double->find(456);
        $double->find(789);

        try {
            $double->received('find')->with(123)->check();
            $this->fail('Expected PHPUnitUnsatisfiedReceivedAssertionException to be thrown.');
        } catch (PHPUnitUnsatisfiedReceivedAssertionException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('The following calls to `find` were made during this test:', $message);
            $this->assertStringContainsString('find(456)', $message);
            $this->assertStringContainsString('find(789)', $message);
            $this->assertStringNotContainsString('similar call', $message);
        }
    }

    public function test_received_never_passes_when_the_method_was_not_called(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->received('delete')->never();
    }

    public function test_received_never_fails_when_the_method_was_called(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->delete(1);

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);
        $this->expectExceptionMessageMatches('/expected `delete\(any arguments\)` to never be called, but it was called 1 time/');

        $double->received('delete')->never();
    }

    /**
     * never()'s violation means a call's arguments already matched fine —
     * the problem is that it happened at all, not what its arguments were
     * — so there's nothing to diff even though exactly one call was
     * recorded, unlike the too-few-matches case above.
     */
    public function test_received_never_violation_does_not_diff_even_with_one_recorded_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(new Book('Dune'));

        $double->find(123);

        try {
            $double->received('find')->with(123)->never()->check();
            $this->fail('Expected PHPUnitUnsatisfiedReceivedAssertionException to be thrown.');
        } catch (PHPUnitUnsatisfiedReceivedAssertionException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('The following calls to `find` were made during this test: `find(123)`', $message);
            $this->assertStringNotContainsString('similar call', $message);
        }
    }

    public function test_received_times_requires_the_exact_count(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->delete(1);
        $double->delete(2);

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);
        $this->expectExceptionMessageMatches('/expected `delete\(any arguments\)` to be called exactly 3 times, but it was called 2 times/');

        $double->received('delete')->times(3);
    }

    public function test_received_composes_with_and_never_to_assert_specific_arguments_were_not_received(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $protected = new Book('Protected');

        $double->save(new Book('Fine to delete'));

        // Composing with()+never() only works because the check happens once,
        // at chain destruction, not eagerly on with() itself — see
        // ReceivedAssertion's docblock.
        $double->received('save')->with($protected)->never();
    }

    public function test_received_rejects_an_undeclared_method_name(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(UnknownMethodException::class);
        $this->expectExceptionMessage('bogus');

        $double->received('bogus');
    }

    public function test_received_suggests_the_closest_declared_method_name_for_a_likely_typo(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(UnknownMethodException::class);
        $this->expectExceptionMessage('Did you mean `save`?');

        $double->received('sav');
    }

    public function test_received_rejects_a_static_method(): void
    {
        $double = Double::for(HasStaticMethod::class);

        $this->expectException(StaticMethodException::class);
        $this->expectExceptionMessage('static method');

        $double->received('make');
    }

    public function test_received_rejects_a_magic_method(): void
    {
        $double = Double::for(HasMagicMethod::class);

        $this->expectException(MagicMethodException::class);
        $this->expectExceptionMessage('magic method');

        $double->received('__call');
    }

    public function test_received_passes_for_a_doubleable_magic_method(): void
    {
        $double = Double::for(HasInvokeMethod::class);
        $double->allows('__invoke')->returns('doubled');

        $double(1);

        $double->received('__invoke');
    }

    public function test_unused_passes_when_nothing_was_called_at_all(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->unused();
    }

    public function test_unused_fails_when_any_method_was_called(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->delete(1);

        $this->expectException(PHPUnitUnusedAssertionException::class);
        $this->expectExceptionMessage('Double `BookRepositoryInterface` expected no calls at all, but received: `delete(1)`.');

        $double->unused();
    }

    /**
     * The gap this exists to close: a spy that should never have been
     * touched, across every method it declares, not just one you happened
     * to name — see DoubleControlMethods::unused()'s docblock.
     */
    public function test_unused_fails_and_lists_every_call_across_different_methods(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->save(new Book('Dune'));
        $double->delete(1);

        $this->expectException(PHPUnitUnusedAssertionException::class);
        $this->expectExceptionMessageMatches('/received: `save\(.*Book.*\)`, `delete\(1\)`/');

        $double->unused();
    }

    public function test_verify_passes_when_no_expectations_were_configured_at_all(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $double->verify();
    }

    public function test_verify_failure_correlates_other_calls_observed_for_the_same_method(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(null);
        $double->expects('find')->with(123)->returns(new Book('Dune'));

        $double->find(456);

        try {
            $double->verify();
            $this->fail('Expected UnsatisfiedExpectationException to be thrown.');
        } catch (PHPUnitUnsatisfiedExpectationException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('expected `find(123)` to be called exactly 1 time, but it was never called', $message);
            $this->assertStringContainsString('The following similar call was made to `find`:', $message);
            $this->assertStringContainsString("  id:\n    - 123\n    + 456", $message);
        }
    }

    /**
     * The one-observed-call case can go further than correlation: since
     * there's exactly one real call to pair the expectation against, the
     * message can name the real parameter and say how it differed.
     *
     * A wildcard allows() absorbs the mismatched call so it reaches
     * verify() at all — without it, expects()'s own per-method strictness
     * (see LooseModeTest::test_an_expects_configured_method_throws_immediately_on_a_mismatched_call())
     * would reject find(456) immediately, before verify() ever ran.
     */
    public function test_verify_failure_names_the_mismatched_argument_by_its_real_parameter_name(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(null);
        $double->expects('find')->with(123)->returns(new Book('Dune'));

        $double->find(456);

        try {
            $double->verify();
            $this->fail('Expected UnsatisfiedExpectationException to be thrown.');
        } catch (PHPUnitUnsatisfiedExpectationException $exception) {
            $this->assertStringContainsString("  id:\n    - 123\n    + 456", $exception->getMessage());
        }
    }

    /**
     * Two or more registered expectations for the same method leaves no
     * fact-based way to say which one a given observed call was "supposed"
     * to match — the message correlates but doesn't guess at a diff.
     */
    public function test_verify_failure_omits_the_argument_detail_when_more_than_one_call_was_observed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->returns(null);
        $double->expects('find')->with(123)->returns(new Book('Dune'));

        $double->find(456);
        $double->find(789);

        try {
            $double->verify();
            $this->fail('Expected UnsatisfiedExpectationException to be thrown.');
        } catch (PHPUnitUnsatisfiedExpectationException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('The following calls to `find` were made during this test:', $message);
            $this->assertStringNotContainsString('similar call', $message);
            $this->assertStringNotContainsString('  id:', $message);
        }
    }

    /**
     * Unlike the flat, single-line summary this replaced, the labeled block
     * format doesn't get noisy as more arguments differ — every differing
     * argument gets named by its real parameter name, each with its own
     * diff, alongside whichever arguments still matched.
     *
     * Same wildcard-allows()-absorbs-the-call reasoning as
     * test_verify_failure_names_the_mismatched_argument_by_its_real_parameter_name()
     * above — otherwise expects() would reject combine('Baz', 'y') immediately.
     */
    public function test_verify_failure_names_every_differing_argument_when_several_differ(): void
    {
        $double = Double::for(VariadicInterface::class);
        $double->allows('combine')->returns('');
        $double->expects('combine')->with('baz', 'x')->returns('stubbed');

        $double->combine('Baz', 'y');

        try {
            $double->verify();
            $this->fail('Expected UnsatisfiedExpectationException to be thrown.');
        } catch (PHPUnitUnsatisfiedExpectationException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('The following similar call was made to `combine`:', $message);
            $this->assertStringContainsString("  glue:\n    - 'baz'\n    + 'Baz'", $message);
            $this->assertStringContainsString("  parts:\n    - 'x'\n    + 'y'", $message);
        }
    }

    public function test_verify_all_passes_when_every_double_created_since_arming_is_satisfied(): void
    {
        Double::enableAutoVerify();

        $first = Double::for(BookRepositoryInterface::class);
        $second = Double::for(BookRepositoryInterface::class);
        $first->expects('delete')->returns(null);
        $second->allows('save')->returns(true);

        $first->delete(1);

        Double::verifyAll();
    }

    /**
     * PHPUnit learns a check ran via registerPass() bumping its own Assert
     * counter — invisible to any other runner. verifyAll()'s return value is
     * that same signal made framework-agnostic: how many pending doubles and
     * pending received() assertions it just checked, so a non-PHPUnit runner
     * has something to treat as "this test asserted something" without
     * reaching for PHPUnit\Framework\Assert.
     */
    public function test_verify_all_returns_the_number_of_checks_it_performed(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);
        $double->delete(1);
        $double->save(new Book('Dune'));
        $this->heldAssertion = $double->received('save');

        $this->assertSame(2, Double::verifyAll());
    }

    public function test_verify_all_returns_zero_when_nothing_was_pending(): void
    {
        Double::enableAutoVerify();

        $this->assertSame(0, Double::verifyAll());
    }

    public function test_verify_all_fails_when_any_double_created_since_arming_has_an_unmet_expectation(): void
    {
        Double::enableAutoVerify();

        $satisfied = Double::for(BookRepositoryInterface::class);
        $unsatisfied = Double::for(BookRepositoryInterface::class);
        $satisfied->expects('delete')->returns(null);
        $unsatisfied->expects('save')->returns(true);

        $satisfied->delete(1);

        $this->expectException(PHPUnitUnsatisfiedExpectationException::class);
        $this->expectExceptionMessageMatches('/expected `save\(any arguments\)` to be called exactly 1 time, but it was never called/s');

        Double::verifyAll();
    }

    public function test_verify_all_only_covers_doubles_created_after_arming(): void
    {
        // Created before arming — verifyAll() must never see this one, even
        // though it has a real unmet expectation.
        $before = Double::for(BookRepositoryInterface::class);
        $before->expects('delete')->returns(null);

        Double::enableAutoVerify();

        $after = Double::for(BookRepositoryInterface::class);
        $after->expects('save')->returns(true);
        $after->save(new Book('Dune'));

        Double::verifyAll();
    }

    public function test_verify_all_drains_pending_doubles_so_a_second_call_is_a_no_op(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);
        $double->delete(1);

        Double::verifyAll();

        // $double already left the pending list on the call above, so this
        // has nothing left to check regardless of $double's own state.
        $this->assertSame(0, Double::verifyAll());
    }

    /**
     * The scenario ReceivedAssertion's docblock describes: a received()
     * chain stored somewhere that outlives the statement that created it
     * (here, $this->heldAssertion — see that property's own docblock for
     * why it has to be a property, not a local, to actually prove this),
     * so it can't have reached its own __destruct() by the time verifyAll()
     * runs. Before $pendingReceived existed, verifyAll() had no way to know
     * this assertion existed at all and would pass regardless of whether
     * "save" was ever actually called.
     */
    public function test_verify_all_checks_a_received_assertion_held_past_the_test_method(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $this->heldAssertion = $double->received('save');

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);

        Double::verifyAll();
    }

    public function test_verify_all_passes_a_satisfied_received_assertion_held_past_the_test_method(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->save(new Book('Dune'));
        $this->heldAssertion = $double->received('save');

        Double::verifyAll();
    }

    public function test_verify_all_drains_pending_received_assertions_so_a_second_call_is_a_no_op(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->save(new Book('Dune'));
        $this->heldAssertion = $double->received('save');

        Double::verifyAll();

        // $this->heldAssertion already left the pending list and was
        // checked on the call above, so this has nothing left to check
        // regardless of its own state.
        $this->assertSame(0, Double::verifyAll());
    }

    public function test_pause_auto_verify_leaves_the_live_state_off_and_empty(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        Double::pauseAutoVerify();

        // Pausing reset the live state, so this double — created after
        // enabling but before the pause — was lifted out along with it.
        // A fresh verifyAll() against the now-empty live state has nothing
        // left to check, even though $double's own expectation is unmet —
        // there is deliberately nothing left to assert here.
        $this->expectNotToPerformAssertions();

        Double::verifyAll();
    }

    public function test_resume_auto_verify_reinstalls_a_previously_paused_snapshot(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        $snapshot = Double::pauseAutoVerify();

        Double::resumeAutoVerify($snapshot);

        $this->expectException(PHPUnitUnsatisfiedExpectationException::class);
        $this->expectExceptionMessageMatches('/expected `delete\(any arguments\)` to be called exactly 1 time, but it was never called/s');

        Double::verifyAll();
    }

    public function test_resume_auto_verify_round_trips_a_held_received_assertion(): void
    {
        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $this->heldAssertion = $double->received('save');

        $snapshot = Double::pauseAutoVerify();
        Double::resumeAutoVerify($snapshot);

        $this->expectException(PHPUnitUnsatisfiedReceivedAssertionException::class);

        Double::verifyAll();
    }

    public function test_resume_auto_verify_overwrites_rather_than_merges_the_live_state(): void
    {
        Double::enableAutoVerify();

        $parked = Double::for(BookRepositoryInterface::class);
        $parked->expects('delete')->returns(null);
        $parked->delete(1);

        $snapshot = Double::pauseAutoVerify();

        Double::enableAutoVerify();

        // Live again after the second enableAutoVerify() — resuming
        // $snapshot must replace this, not merge with it, so its unmet
        // expectation is never checked.
        $overwritten = Double::for(BookRepositoryInterface::class);
        $overwritten->expects('save')->returns(true);

        Double::resumeAutoVerify($snapshot);

        // Only $parked (satisfied) is live now, so this passes even though
        // $overwritten's `save` expectation was never met.
        Double::verifyAll();
    }

    public function test_listen_receives_a_passing_check_event_when_verify_all_succeeds(): void
    {
        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        Double::enableAutoVerify();

        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);
        $double->delete(1);

        Double::verifyAll();

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertNull($events[0]->method);
        $this->assertTrue($events[0]->passed);
        $this->assertNull($events[0]->failure);
    }

    public function test_listen_receives_a_passing_check_event_for_a_satisfied_received_assertion(): void
    {
        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $double = Double::for(BookRepositoryInterface::class);
        $double->delete(1);
        $double->received('delete');

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('delete', $events[0]->method);
        $this->assertTrue($events[0]->passed);
        $this->assertNull($events[0]->failure);
    }

    public function test_listen_receives_a_passing_check_event_for_unused(): void
    {
        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $double = Double::for(BookRepositoryInterface::class);
        $double->unused();

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertNull($events[0]->method);
        $this->assertTrue($events[0]->passed);
        $this->assertNull($events[0]->failure);
    }

    /**
     * The check that motivated this feature: this fires from inside
     * ProxyBehavior/ExceptionFactory at the moment of the unmatched call
     * itself — no enableAutoVerify()/verifyAll() involved — which is exactly
     * the "immediate throws are invisible to introspection" gap the old,
     * internal-only AutoVerifySnapshot-based approach couldn't close.
     */
    public function test_listen_receives_a_failing_check_event_for_an_unmatched_call_on_a_strict_double(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->count();
        } catch (PHPUnitUnexpectedCallException) {
            // notify() already ran inside ExceptionFactory, before this throw.
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('count', $events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitUnexpectedCallException::class, $events[0]->failure);
    }

    public function test_listen_receives_a_failing_check_event_when_a_call_limit_is_exceeded(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('delete')->never();

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->delete(1);
        } catch (PHPUnitExpectationCallLimitExceededException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('delete', $events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitExpectationCallLimitExceededException::class, $events[0]->failure);
    }

    public function test_listen_receives_a_failing_check_event_for_an_out_of_order_call(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->allows('find')->ordered();
        $double->allows('save')->ordered();
        $double->save(new Book('Dune'));

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->find(1);
        } catch (PHPUnitOutOfOrderCallException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('find', $events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitOutOfOrderCallException::class, $events[0]->failure);
    }

    public function test_listen_receives_a_failing_check_event_for_an_argument_mismatch_against_a_required_expectation(): void
    {
        $double = Double::for(BookRepositoryInterface::class)->strict();
        $double->expects('find')->with(123)->returns(new Book('Dune'));

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->find(456);
        } catch (PHPUnitExpectationCallMismatchException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('find', $events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitExpectationCallMismatchException::class, $events[0]->failure);
    }

    /**
     * $method is null here, unlike the four immediate-throw checks above —
     * verify()'s unmet-expectations check is inherently whole-double (it can
     * bundle several unmet expectations across different methods into one
     * failure already; see UnsatisfiedExpectationException).
     */
    public function test_listen_receives_a_failing_check_event_when_verify_finds_an_unmet_expectation(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->expects('delete')->returns(null);

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->verify();
        } catch (PHPUnitUnsatisfiedExpectationException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertNull($events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitUnsatisfiedExpectationException::class, $events[0]->failure);
    }

    public function test_listen_receives_a_failing_check_event_for_an_unsatisfied_received_assertion(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->received('delete');
        } catch (PHPUnitUnsatisfiedReceivedAssertionException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertSame('delete', $events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitUnsatisfiedReceivedAssertionException::class, $events[0]->failure);
    }

    public function test_listen_receives_a_failing_check_event_for_unused_when_a_call_was_observed(): void
    {
        $double = Double::for(BookRepositoryInterface::class);
        $double->delete(1);

        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $double->unused();
        } catch (PHPUnitUnusedAssertionException) {
        }

        $this->assertCount(1, $events);
        $this->assertSame('BookRepositoryInterface', $events[0]->label);
        $this->assertNull($events[0]->method);
        $this->assertFalse($events[0]->passed);
        $this->assertInstanceOf(PHPUnitUnusedAssertionException::class, $events[0]->failure);
    }

    public function test_listen_notifies_every_registered_listener(): void
    {
        $firstCount = 0;
        $secondCount = 0;
        Double::listen(function (CheckEvent $event) use (&$firstCount): void {
            $firstCount++;
        });
        Double::listen(function (CheckEvent $event) use (&$secondCount): void {
            $secondCount++;
        });

        $double = Double::for(BookRepositoryInterface::class);
        $double->unused();

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount);
    }

    /**
     * fabricationLimitExceeded() is a Loose-mode recursion guard, not a
     * check the test authored — deliberately outside the scope listen()
     * covers (see CheckEvent's own docblock). Called directly, the same way
     * ExceptionFactoryTest already does, since reproducing the real
     * recursive-fabrication scenario needs nothing this test cares about
     * beyond confirming ExceptionFactory itself never notifies for it.
     */
    public function test_fabrication_limit_exceeded_does_not_emit_a_check_event(): void
    {
        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        ExceptionFactory::fabricationLimitExceeded('SecondLink', 'toThird', 'ThirdLink', 1);

        $this->assertSame([], $events);
    }

    public function test_clear_listeners_stops_further_notifications(): void
    {
        /** @var list<CheckEvent> $events */
        $events = [];
        Double::listen(function (CheckEvent $event) use (&$events): void {
            $events[] = $event;
        });

        Double::clearListeners();

        $double = Double::for(BookRepositoryInterface::class);
        $double->unused();

        $this->assertSame([], $events);
    }
}
