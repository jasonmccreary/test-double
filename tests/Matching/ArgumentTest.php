<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Matching;

use JMac\Testing\Matching\AllMatcher;
use JMac\Testing\Matching\AnyMatcher;
use JMac\Testing\Matching\Argument;
use JMac\Testing\Matching\ContainsMatcher;
use JMac\Testing\Matching\Matcher;
use JMac\Testing\Matching\NoneMatcher;
use JMac\Testing\Matching\NotMatcher;
use JMac\Testing\Matching\PatternMatcher;
use JMac\Testing\Matching\PredicateMatcher;
use JMac\Testing\Matching\RemainingMatcher;
use JMac\Testing\Matching\SameMatcher;
use JMac\Testing\Matching\TypeMatcher;
use JMac\Testing\Tests\Support\Book;
use PHPUnit\Framework\TestCase;

final class ArgumentTest extends TestCase
{
    public function test_any_produces_an_any_matcher(): void
    {
        $this->assertInstanceOf(AnyMatcher::class, Argument::any());
    }

    public function test_type_produces_a_type_matcher_for_the_given_class(): void
    {
        $matcher = Argument::type(Book::class);

        $this->assertInstanceOf(TypeMatcher::class, $matcher);
        $this->assertTrue($matcher->matches(new Book('Some Title')));
    }

    public function test_type_produces_a_type_matcher_for_a_builtin_type_name(): void
    {
        $matcher = Argument::type('int');

        $this->assertInstanceOf(TypeMatcher::class, $matcher);
        $this->assertTrue($matcher->matches(42));
        $this->assertFalse($matcher->matches('42'));
    }

    public function test_satisfies_produces_a_predicate_matcher(): void
    {
        $matcher = Argument::satisfies(fn (int $id): bool => $id > 100);

        $this->assertInstanceOf(PredicateMatcher::class, $matcher);
        $this->assertTrue($matcher->matches(101));
        $this->assertFalse($matcher->matches(1));
    }

    public function test_capture_produces_a_matcher_that_matches_anything(): void
    {
        $captured = null;
        $matcher = Argument::capture($captured);

        $this->assertInstanceOf(Matcher::class, $matcher);
        $this->assertTrue($matcher->matches('anything'));
    }

    public function test_remaining_produces_a_remaining_matcher(): void
    {
        $this->assertInstanceOf(RemainingMatcher::class, Argument::remaining());
    }

    public function test_none_produces_a_none_matcher(): void
    {
        $this->assertInstanceOf(NoneMatcher::class, Argument::none());
    }

    public function test_all_produces_an_all_matcher(): void
    {
        $matcher = Argument::all(fn (int $id, string $name): bool => $id > 0 && $name !== '');

        $this->assertInstanceOf(AllMatcher::class, $matcher);
        $this->assertTrue($matcher->matches([1, 'taylor']));
        $this->assertFalse($matcher->matches([0, 'taylor']));
    }

    public function test_same_produces_a_same_matcher(): void
    {
        $book = new Book('Some Title');
        $matcher = Argument::same($book);

        $this->assertInstanceOf(SameMatcher::class, $matcher);
        $this->assertTrue($matcher->matches($book));
        $this->assertFalse($matcher->matches(new Book('Some Title')));
    }

    public function test_not_produces_a_not_matcher(): void
    {
        $matcher = Argument::not(5);

        $this->assertInstanceOf(NotMatcher::class, $matcher);
        $this->assertTrue($matcher->matches(6));
        $this->assertFalse($matcher->matches(5));
    }

    public function test_matches_produces_a_pattern_matcher(): void
    {
        $matcher = Argument::matches('/^\d+$/');

        $this->assertInstanceOf(PatternMatcher::class, $matcher);
        $this->assertTrue($matcher->matches('123'));
        $this->assertFalse($matcher->matches('abc'));
    }

    public function test_contains_produces_a_contains_matcher(): void
    {
        $matcher = Argument::contains('draft');

        $this->assertInstanceOf(ContainsMatcher::class, $matcher);
        $this->assertTrue($matcher->matches(['draft']));
        $this->assertFalse($matcher->matches(['published']));
    }

    public function test_every_produced_matcher_implements_the_matcher_contract(): void
    {
        $captured = null;

        $this->assertInstanceOf(Matcher::class, Argument::any());
        $this->assertInstanceOf(Matcher::class, Argument::type(Book::class));
        $this->assertInstanceOf(Matcher::class, Argument::satisfies(fn (): bool => true));
        $this->assertInstanceOf(Matcher::class, Argument::capture($captured));
        $this->assertInstanceOf(Matcher::class, Argument::remaining());
        $this->assertInstanceOf(Matcher::class, Argument::none());
        $this->assertInstanceOf(Matcher::class, Argument::same($captured));
        $this->assertInstanceOf(Matcher::class, Argument::not(5));
        $this->assertInstanceOf(Matcher::class, Argument::matches('/x/'));
        $this->assertInstanceOf(Matcher::class, Argument::contains('x'));
        $this->assertInstanceOf(Matcher::class, Argument::any('x', 'y'));
    }
}
