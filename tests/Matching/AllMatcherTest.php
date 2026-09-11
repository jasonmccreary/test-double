<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Matching;

use JMac\Testing\Matching\AllMatcher;
use PHPUnit\Framework\TestCase;

final class AllMatcherTest extends TestCase
{
    /**
     * Unlike every other matcher, matches() here receives the whole real
     * argument list, spread into the predicate positionally — never a
     * single value.
     */
    public function test_matches_spreads_the_argument_list_into_the_predicate(): void
    {
        $matcher = new AllMatcher(fn (int $id, string $name): bool => $id > 0 && $name !== '');

        $this->assertTrue($matcher->matches([1, 'taylor']));
        $this->assertFalse($matcher->matches([0, 'taylor']));
        $this->assertFalse($matcher->matches([1, '']));
    }

    public function test_describe_renders_as_all(): void
    {
        $this->assertSame('all(...)', (new AllMatcher(fn (): bool => true))->describe());
    }

    public function test_explain_mismatch_is_null_when_it_matches(): void
    {
        $matcher = new AllMatcher(fn (int $id): bool => $id > 0);

        $this->assertNull($matcher->explainMismatch([1]));
    }

    public function test_explain_mismatch_describes_the_whole_argument_list(): void
    {
        $matcher = new AllMatcher(fn (int $id, string $name): bool => $id > 0 && $name !== '');

        $this->assertSame(
            "arguments did not jointly satisfy predicate: (0, 'taylor')",
            $matcher->explainMismatch([0, 'taylor']),
        );
    }
}
