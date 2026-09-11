<?php

declare(strict_types=1);

namespace JMac\Testing\Matching;

use JMac\Testing\Diagnostics\ArgumentFormatter;

/**
 * Argument::all($predicate) — the one matcher that sees every real argument
 * at once instead of a single position, e.g.
 * `Argument::all(fn ($id, $name) => $id > 0 && $name !== '')`.
 * Must be the only argument passed to with() (same rule as NoneMatcher) —
 * MethodExpectation special-cases it in matchesArguments()/compareArguments(),
 * short-circuiting positional arity checking entirely, since the predicate's
 * own signature decides how many arguments it cares about, the same way
 * Mockery's withArgs(closure) worked.
 */
final class AllMatcher implements Matcher
{
    /** @var callable(mixed...): bool */
    private $predicate;

    public function __construct(callable $predicate)
    {
        $this->predicate = $predicate;
    }

    /**
     * @param  list<mixed>  $actual  the whole real argument list, never a
     *                               single value — only ever invoked this
     *                               way, via MethodExpectation's special
     *                               case for this matcher.
     */
    public function matches(mixed $actual): bool
    {
        return (bool) ($this->predicate)(...$actual);
    }

    public function describe(): string
    {
        return 'all(...)';
    }

    /**
     * @param  list<mixed>  $actual
     */
    public function explainMismatch(mixed $actual): ?string
    {
        if ($this->matches($actual)) {
            return null;
        }

        return sprintf('arguments did not jointly satisfy predicate: (%s)', ArgumentFormatter::describe($actual));
    }
}
