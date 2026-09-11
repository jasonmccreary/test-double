<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Exceptions;

use JMac\Testing\Double;
use JMac\Testing\Engine\MethodExpectation;
use JMac\Testing\Matching\Argument;
use JMac\Testing\Matching\PatternMatcher;
use JMac\Testing\Tests\Support\Book;
use JMac\Testing\Tests\Support\BookRepositoryInterface;

/**
 * Golden-file coverage for the plain \InvalidArgumentException/\LogicException
 * messages thrown directly by the public API (Double::for(), Argument,
 * PatternMatcher, MethodExpectation's with()/returns()/throws()/times()) —
 * as opposed to ExceptionMessagesTest, which covers the dedicated Diagnostic
 * exception classes under src/Exceptions. Both are "every potential
 * exception message this library can produce," just split by which
 * mechanism renders them.
 */
final class ValidationMessagesTest extends GoldenFileTestCase
{
    public function test_renders_for_with_no_targets(): void
    {
        try {
            Double::for();
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('for-requires-at-least-one-target', $exception->getMessage());
        }
    }

    public function test_renders_for_multi_target_rejecting_a_real_instance(): void
    {
        try {
            Double::for(BookRepositoryInterface::class, new Book('Some Title'));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('for-multi-target-rejects-real-instance', $exception->getMessage());
        }
    }

    public function test_renders_state_for_on_a_non_double_object(): void
    {
        try {
            Double::stateFor(new Book('Some Title'));
            $this->fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            $this->assertMatchesGolden('state-for-not-a-double', $exception->getMessage());
        }
    }

    public function test_renders_argument_not_rejecting_a_matcher(): void
    {
        try {
            Argument::not(Argument::any());
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('argument-not-rejects-matcher', $exception->getMessage());
        }
    }

    public function test_renders_pattern_matcher_invalid_regex(): void
    {
        try {
            new PatternMatcher('/unterminated');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('pattern-matcher-invalid-regex', $exception->getMessage());
        }
    }

    public function test_renders_with_remaining_not_last(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->with(Argument::remaining(), 1);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('with-remaining-must-be-last', $exception->getMessage());
        }
    }

    public function test_renders_with_none_not_alone(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->with(Argument::none(), 1);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('with-none-must-be-alone', $exception->getMessage());
        }
    }

    public function test_renders_with_all_not_alone(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->with(Argument::all(fn (): bool => true), 1);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('with-all-must-be-alone', $exception->getMessage());
        }
    }

    public function test_renders_returns_with_no_values(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->returns();
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('returns-requires-at-least-one-value', $exception->getMessage());
        }
    }

    public function test_renders_throws_with_no_exceptions(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->throws();
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('throws-requires-at-least-one-exception', $exception->getMessage());
        }
    }

    public function test_renders_times_rejecting_count_and_minimum_together(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->times(1, minimum: 1);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('times-rejects-count-and-minimum', $exception->getMessage());
        }
    }

    public function test_renders_times_requiring_an_argument(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->times();
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('times-requires-an-argument', $exception->getMessage());
        }
    }

    public function test_renders_times_rejecting_minimum_greater_than_maximum(): void
    {
        $expectation = new MethodExpectation('find', required: false);

        try {
            $expectation->times(minimum: 3, maximum: 1);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertMatchesGolden('times-rejects-minimum-greater-than-maximum', $exception->getMessage());
        }
    }
}
