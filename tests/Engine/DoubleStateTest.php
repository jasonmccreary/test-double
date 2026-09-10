<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Engine;

use JMac\Testing\Engine\DoubleState;
use JMac\Testing\Engine\MethodExpectation;
use JMac\Testing\Engine\Mode;
use JMac\Testing\Exceptions\ModeConfigurationException;
use JMac\Testing\Tests\Support\BookRepositoryInterface;
use JMac\Testing\Tests\Support\Fillable;
use JMac\Testing\Tests\Support\VariadicInterface;
use PHPUnit\Framework\TestCase;

final class DoubleStateTest extends TestCase
{
    public function test_target_and_label_are_exposed(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertSame(BookRepositoryInterface::class, $state->target());
        $this->assertSame('BookRepositoryInterface', $state->label());
    }

    public function test_mode_defaults_to_loose_when_unset(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertSame(Mode::Loose, $state->mode());
    }

    public function test_set_mode_can_only_happen_once(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $state->setMode(Mode::Strict);

        $this->expectException(ModeConfigurationException::class);

        $state->setMode(Mode::Strict);
    }

    public function test_expectations_for_filters_by_method_name_and_preserves_registration_order(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $find = new MethodExpectation('find', required: false);
        $save = new MethodExpectation('save', required: false);
        $anotherFind = new MethodExpectation('find', required: false);

        $state->registerExpectation($find);
        $state->registerExpectation($save);
        $state->registerExpectation($anotherFind);

        $this->assertSame([$find, $anotherFind], $state->expectationsFor('find'));
        $this->assertSame([$save], $state->expectationsFor('save'));
    }

    public function test_record_call_and_calls_for_track_every_call_regardless_of_matching(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $state->recordCall('find', [1]);
        $state->recordCall('save', [new \stdClass]);
        $state->recordCall('find', [2]);

        $this->assertSame([[1], [2]], $state->callsFor('find'));
    }

    public function test_unmet_expectations_excludes_satisfied_ones(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $satisfied = new MethodExpectation('save', required: true);
        $unmet = new MethodExpectation('delete', required: true);

        $satisfied->recordMatch();

        $state->registerExpectation($satisfied);
        $state->registerExpectation($unmet);

        $this->assertSame([$unmet], $state->unmetExpectations());
    }

    public function test_a_freshly_created_double_is_not_fabricated(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertFalse($state->isFabricated());
        $this->assertSame(0, $state->fabricationDepth());
    }

    public function test_mark_fabricated_records_the_depth(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $state->markFabricated(2);

        $this->assertTrue($state->isFabricated());
        $this->assertSame(2, $state->fabricationDepth());
    }

    public function test_target_candidates_is_a_single_element_list_for_an_ordinary_double(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertSame([BookRepositoryInterface::class], $state->targetCandidates());
    }

    public function test_target_candidates_splits_an_intersection_target_on_ampersand(): void
    {
        $state = new DoubleState('Fillable&Sized', 'Fillable&Sized');

        $this->assertSame(['Fillable', 'Sized'], $state->targetCandidates());
    }

    public function test_parameter_names_reflects_the_real_declared_names_in_order(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertSame(['id'], $state->parameterNames('find'));
    }

    /**
     * A variadic parameter's own name (not one entry per actual argument —
     * that expansion belongs to whichever caller knows the actual argument
     * count, see Double::verifyState()).
     */
    public function test_parameter_names_includes_a_variadic_parameters_own_name_once(): void
    {
        $state = new DoubleState(VariadicInterface::class, 'VariadicInterface');

        $this->assertSame(['glue', 'parts'], $state->parameterNames('combine'));
    }

    /**
     * parameterNames() is built on declaringCandidate(), which already
     * walks every intersection member to find the one that declares the
     * method — this proves that resolution reaches all the way through to
     * reflecting the real parameter names, not just locating the class.
     * Fillable (no params) is listed first and doesn't declare `find`, so
     * this also proves resolution doesn't stop at the first candidate.
     */
    public function test_parameter_names_resolves_across_an_intersection_target(): void
    {
        $target = Fillable::class.'&'.BookRepositoryInterface::class;
        $state = new DoubleState($target, $target);

        $this->assertSame(['id'], $state->parameterNames('find'));
    }

    public function test_configure_passthru_sets_the_mode(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $state->configurePassthru();

        $this->assertSame(Mode::Passthru, $state->mode());
    }

    public function test_configure_passthru_cannot_run_twice(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $state->configurePassthru();

        $this->expectException(ModeConfigurationException::class);

        $state->configurePassthru();
    }

    public function test_known_instance_is_null_until_remembered(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertNull($state->knownInstance());
    }

    public function test_remember_real_instance_does_not_change_the_mode(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $real = new \stdClass;

        $state->rememberRealInstance($real);

        $this->assertSame($real, $state->knownInstance());
        $this->assertSame(Mode::Loose, $state->mode());
    }

    public function test_ordered_expectations_filters_to_only_those_marked_in_order_and_preserves_registration_order(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');
        $find = (new MethodExpectation('find', required: false))->ordered();
        $save = new MethodExpectation('save', required: false);
        $delete = (new MethodExpectation('delete', required: false))->ordered();

        $state->registerExpectation($find);
        $state->registerExpectation($save);
        $state->registerExpectation($delete);

        $this->assertSame([$find, $delete], $state->orderedExpectations());
    }

    public function test_order_cursor_starts_at_zero_and_advances_explicitly(): void
    {
        $state = new DoubleState(BookRepositoryInterface::class, 'BookRepositoryInterface');

        $this->assertSame(0, $state->orderCursor());

        $state->advanceOrderCursor(2);

        $this->assertSame(2, $state->orderCursor());
    }
}
