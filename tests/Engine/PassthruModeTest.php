<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Engine;

use JMac\Testing\Double;
use JMac\Testing\Exceptions\PassthruAutoInstantiationException;
use JMac\Testing\Exceptions\PassthruTypeMismatchException;
use JMac\Testing\Integrations\PHPUnit\PHPUnitExpectationCallMismatchException;
use JMac\Testing\Tests\Support\BookRepositoryInterface;
use JMac\Testing\Tests\Support\ClonesItselfDuringCall;
use JMac\Testing\Tests\Support\ConcreteLogger;
use JMac\Testing\Tests\Support\ExtendedGreeter;
use JMac\Testing\Tests\Support\InstantiableLogger;
use JMac\Testing\Tests\Support\LoggerInterface;
use JMac\Testing\Tests\Support\RealLogger;
use JMac\Testing\Tests\Support\SelfCallingCalculator;
use JMac\Testing\Tests\Support\StatefulGreeter;
use PHPUnit\Framework\TestCase;

final class PassthruModeTest extends TestCase
{
    public function test_passthru_with_an_instance_delegates_unmatched_calls(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for(InstantiableLogger::class)->passthru($real);

        $this->assertTrue($double->log('hello'));
    }

    public function test_passthru_still_intercepts_configured_calls(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for(InstantiableLogger::class)->passthru($real);
        $double->allows('log')->returns(false);

        $this->assertFalse($double->log('hello'));
    }

    /**
     * expects() raises the bar for its own method regardless of mode (see
     * ExpectationCallMismatchException) — Passthru is no exception. But
     * Passthru's whole premise is "real behavior unless overridden," so this
     * particular rejection is worth flagging in its own failure message,
     * rather than leaving it to read like the fallback-to-real-object case
     * silently broke.
     */
    public function test_passthru_with_expects_rejects_an_unmatched_call_instead_of_delegating(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for(InstantiableLogger::class)->passthru($real);
        $double->expects('log')->with('hello')->returns(true);

        try {
            $double->log('goodbye');
            $this->fail('Expected PHPUnitExpectationCallMismatchException to be thrown.');
        } catch (PHPUnitExpectationCallMismatchException $exception) {
            $this->assertStringContainsString(
                'Note: this double is in passthru mode, but using `expects()` means every call needs to be configured.',
                $exception->getMessage(),
            );
        }
    }

    public function test_passthru_delegated_calls_are_still_recorded_for_spy_assertions(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for(InstantiableLogger::class)->passthru($real);

        $double->log('hello');

        $this->assertSame([['hello']], Double::stateFor($double)->callsFor('log'));
    }

    public function test_passthru_with_no_argument_runs_real_methods(): void
    {
        $double = Double::for(InstantiableLogger::class)->passthru();

        $this->assertTrue($double->log('hello'));
    }

    public function test_passthru_with_no_argument_on_an_interface_target_fails_clearly(): void
    {
        $double = Double::for(BookRepositoryInterface::class);

        $this->expectException(PassthruAutoInstantiationException::class);
        $this->expectExceptionMessage('->passthru($existingInstance)');

        $double->passthru();
    }

    /**
     * ConcreteLogger's constructor always throws (see its own docblock) —
     * this proves passthru() with no argument never runs it at all. If it
     * did, this would throw before log() ever got a chance to run.
     */
    public function test_passthru_with_no_argument_never_runs_the_real_constructor(): void
    {
        $double = Double::for(ConcreteLogger::class)->passthru();

        $this->assertTrue($double->log('hello'));
    }

    public function test_for_with_a_real_instance_derives_the_double_from_its_class(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for($real);

        $this->assertInstanceOf(InstantiableLogger::class, $double);
    }

    public function test_for_with_a_real_instance_does_not_change_the_default_mode(): void
    {
        $real = new InstantiableLogger;
        $double = Double::for($real);

        // Loose mode's safe default for a bool return is false — if for()
        // had silently switched to Passthru mode, this would delegate to
        // $real->log() instead and return true.
        $this->assertFalse($double->log('hello'));
    }

    public function test_for_with_a_real_instance_is_used_by_a_later_passthru_with_no_argument(): void
    {
        $real = new StatefulGreeter('Ada');
        $double = Double::for($real)->passthru();

        $this->assertSame('Hello, Ada!', $double->greet());
    }

    public function test_passthru_with_an_explicit_instance_overrides_the_one_remembered_from_for(): void
    {
        $remembered = new StatefulGreeter('Remembered');
        $explicit = new StatefulGreeter('Explicit');
        $double = Double::for($remembered)->passthru($explicit);

        $this->assertSame('Hello, Explicit!', $double->greet());
    }

    public function test_for_with_a_real_instance_still_satisfies_an_interface_the_class_implements(): void
    {
        $real = new RealLogger;
        $double = Double::for($real)->passthru();

        // PHP's own transitive interface inheritance through extends — not
        // anything this library does specially — so the double can still be
        // swapped into an IoC container wherever LoggerInterface is bound.
        $this->assertInstanceOf(LoggerInterface::class, $double);
        $this->assertTrue($double->log('hello'));
    }

    public function test_for_rejects_a_real_instance_mixed_into_a_multi_target_call(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Double::for(new InstantiableLogger, BookRepositoryInterface::class);
    }

    /**
     * The reason passthru runs real code on the double itself rather than a
     * separate wrapped object: calculate() is unstubbed and runs for real,
     * and it internally calls $this->double($value). If that self-call
     * reached the real double() instead of the configured stub, this would
     * be 11 (5 * 2 + 1), not 101.
     */
    public function test_passthru_routes_a_self_call_through_a_configured_stub(): void
    {
        $double = Double::for(SelfCallingCalculator::class)->passthru();
        $double->allows('double')->returns(100);

        $this->assertSame(101, $double->calculate(5));
    }

    public function test_passthru_still_runs_unstubbed_methods_for_real(): void
    {
        $double = Double::for(SelfCallingCalculator::class)->passthru();

        $this->assertSame(11, $double->calculate(5));
    }

    public function test_passthru_self_calls_are_still_recorded_for_spy_assertions(): void
    {
        $double = Double::for(SelfCallingCalculator::class)->passthru();

        $double->calculate(5);

        $this->assertSame([[5]], Double::stateFor($double)->callsFor('calculate'));
        $this->assertSame([[5]], Double::stateFor($double)->callsFor('double'));
    }

    /**
     * The real-world shape this guards against: Eloquent's own
     * HasOneOrMany::firstOrCreate() clones the query builder before retrying
     * a query on the copy. Before Double registered a clone's state, the
     * clone's very next intercepted call threw "Object is not a
     * `Double`-generated double" — a crash inside real framework code the
     * test never wrote, several frames from anything it configured.
     */
    public function test_passthru_real_method_can_clone_itself_and_keep_working(): void
    {
        $double = Double::for(ClonesItselfDuringCall::class)->passthru();
        $double->allows('double')->returns(100);

        $this->assertSame(100, $double->retryOnClone(5));
    }

    public function test_passthru_rejects_an_instance_unrelated_to_the_doubled_class(): void
    {
        $double = Double::for(StatefulGreeter::class);

        $this->expectException(PassthruTypeMismatchException::class);
        $this->expectExceptionMessage('must be `JMac\Testing\Tests\Support\StatefulGreeter` or one of its subclasses');

        $double->passthru(new \stdClass);
    }

    /**
     * A subclass instance is accepted — real PHP subtyping, the same as any
     * type hint would allow. But passthru only ever runs the *doubled*
     * class's own method bodies, never the subclass's overrides: greet()
     * here is StatefulGreeter's real implementation, not ExtendedGreeter's.
     * That's a real, documented limit, not a bug.
     */
    public function test_passthru_accepts_a_subclass_instance_but_only_runs_the_doubled_classs_own_methods(): void
    {
        $double = Double::for(StatefulGreeter::class)->passthru(new ExtendedGreeter('Ada'));

        $this->assertSame('Hello, Ada!', $double->greet());
    }
}
