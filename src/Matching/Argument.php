<?php

declare(strict_types=1);

namespace JMac\Testing\Matching;

/**
 * The argument-matcher facade.
 */
final class Argument
{
    private function __construct() {}

    public static function any(mixed ...$alternatives): Matcher
    {
        return $alternatives === [] ? new AnyMatcher : new AnyOfMatcher($alternatives);
    }

    /**
     * @param  class-string|string  $type
     */
    public static function type(string $type): Matcher
    {
        return new TypeMatcher($type);
    }

    /**
     * @param  callable(mixed): bool  $predicate
     */
    public static function satisfies(callable $predicate): Matcher
    {
        return new PredicateMatcher($predicate);
    }

    /**
     * Unlike every other matcher here, this one sees the whole real argument
     * list at once instead of a single position — must be the only argument
     * passed to with(). Reach for this when a check genuinely spans several
     * arguments together; for a check that only ever cares about one
     * position, `satisfies()` at that position is the better fit.
     *
     * @param  callable(mixed...): bool  $predicate
     */
    public static function all(callable $predicate): Matcher
    {
        return new AllMatcher($predicate);
    }

    public static function capture(mixed &$reference): Matcher
    {
        return new CaptureMatcher($reference);
    }

    public static function remaining(): Matcher
    {
        return new RemainingMatcher;
    }

    public static function none(): Matcher
    {
        return new NoneMatcher;
    }

    public static function same(mixed $expected): Matcher
    {
        return new SameMatcher($expected);
    }

    // Disambiguated by whether an argument was passed at all (func_num_args(),
    // not a null-check) — not(null) is a legitimate "not null" literal match,
    // not the zero-arg case that returns a NegatedArgument instead.
    public static function not(mixed $expected = null): Matcher|NegatedArgument
    {
        if (func_num_args() === 0) {
            return new NegatedArgument;
        }

        if ($expected instanceof Matcher) {
            throw new \InvalidArgumentException(
                'You can\'t use `Argument::not($matcher)` directly — it must prefix a matcher instead. '
                .'For example: `Argument::not()->type(\'int\')` or `Argument::not()->contains($needle)`.',
            );
        }

        return new NotMatcher($expected);
    }

    public static function matches(string $pattern): Matcher
    {
        return new PatternMatcher($pattern);
    }

    public static function contains(mixed $needle): Matcher
    {
        return new ContainsMatcher($needle);
    }
}
