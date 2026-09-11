<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Engine;

use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Engine\ClassGenerator;
use JMac\Testing\Exceptions\InvalidDoubleTargetException;
use JMac\Testing\Exceptions\ReservedNameCollisionException;
use JMac\Testing\Tests\Support\AllowsCollisionInterface;
use JMac\Testing\Tests\Support\ArrayAccessInterface;
use JMac\Testing\Tests\Support\AuthorizerInterface;
use JMac\Testing\Tests\Support\Book;
use JMac\Testing\Tests\Support\BookRepositoryInterface;
use JMac\Testing\Tests\Support\ByRefParamInterface;
use JMac\Testing\Tests\Support\ConcreteLogger;
use JMac\Testing\Tests\Support\ConstDefaultBase;
use JMac\Testing\Tests\Support\ConstDefaultChild;
use JMac\Testing\Tests\Support\ConstDefaultGrandparent;
use JMac\Testing\Tests\Support\EnumDefaultInterface;
use JMac\Testing\Tests\Support\ExpectsCollisionInterface;
use JMac\Testing\Tests\Support\Fillable;
use JMac\Testing\Tests\Support\FinalLogger;
use JMac\Testing\Tests\Support\HasHookedProperty;
use JMac\Testing\Tests\Support\HasInvokeMethod;
use JMac\Testing\Tests\Support\HasMagicMethod;
use JMac\Testing\Tests\Support\HasStaticMethod;
use JMac\Testing\Tests\Support\HookedPropertyInterface;
use JMac\Testing\Tests\Support\IntersectionReturnInterface;
use JMac\Testing\Tests\Support\InvokableInterface;
use JMac\Testing\Tests\Support\MagicMethodInterface;
use JMac\Testing\Tests\Support\NewInInitializerDefault;
use JMac\Testing\Tests\Support\NewInInitializerParamInterface;
use JMac\Testing\Tests\Support\NullableParamInterface;
use JMac\Testing\Tests\Support\PassthruCollisionInterface;
use JMac\Testing\Tests\Support\ProtectedPassthruCollision;
use JMac\Testing\Tests\Support\ReadOnlyLogger;
use JMac\Testing\Tests\Support\ReceivedCollisionInterface;
use JMac\Testing\Tests\Support\RefReturnInterface;
use JMac\Testing\Tests\Support\SelfTypedParamBase;
use JMac\Testing\Tests\Support\SelfTypedParamChild;
use JMac\Testing\Tests\Support\SelfTypedParamGrandparent;
use JMac\Testing\Tests\Support\Sized;
use JMac\Testing\Tests\Support\StaticMethodInterface;
use JMac\Testing\Tests\Support\StrictCollisionInterface;
use JMac\Testing\Tests\Support\Suit;
use JMac\Testing\Tests\Support\UnionTypeInterface;
use JMac\Testing\Tests\Support\UnusedCollisionInterface;
use JMac\Testing\Tests\Support\VariadicInterface;
use JMac\Testing\Tests\Support\VerifyCollisionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

final class ClassGeneratorTest extends TestCase
{
    public function test_generates_a_class_implementing_a_target_interface(): void
    {
        $generated = (new ClassGenerator)->generate(BookRepositoryInterface::class);

        $this->assertTrue(class_exists($generated));
        $this->assertTrue(is_subclass_of($generated, BookRepositoryInterface::class));
    }

    public function test_generates_a_class_extending_a_target_class_without_invoking_its_constructor(): void
    {
        $generated = (new ClassGenerator)->generate(ConcreteLogger::class);

        $this->assertTrue(is_subclass_of($generated, ConcreteLogger::class));

        // Instantiating via the real (still-inherited) constructor would throw;
        // the generated class's own factory bypasses it entirely.
        $instance = $generated::__td_instantiate();

        $this->assertInstanceOf(ConcreteLogger::class, $instance);
    }

    /**
     * Double::for()'s @template/@return T&DoubleInterface docblock is
     * only sound if this is genuinely true at runtime, for both the
     * `implements` and `extends` code paths in buildSource() — not just a
     * docblock claim nothing enforces.
     */
    public function test_generates_a_class_implementing_test_double_interface_for_an_interface_target(): void
    {
        $generated = (new ClassGenerator)->generate(BookRepositoryInterface::class);

        $this->assertTrue(is_subclass_of($generated, DoubleInterface::class));
    }

    public function test_generates_a_class_implementing_test_double_interface_for_a_class_target(): void
    {
        $generated = (new ClassGenerator)->generate(ConcreteLogger::class);

        $this->assertTrue(is_subclass_of($generated, DoubleInterface::class));
    }

    /**
     * Regression check: ArrayAccess::offsetExists()/offsetGet()/etc. only
     * declare a *tentative* return type (PHP 8.1+'s mechanism for internal
     * interfaces phasing in a return type without an immediate BC break) —
     * ReflectionMethod::getReturnType() returns null for these, so before
     * falling back to getTentativeReturnType(), the generated override had
     * no return type at all, which PHP silently accepts but flags with a
     * "should either be compatible... or use #[ReturnTypeWillChange]"
     * deprecation on every single call.
     */
    public function test_generates_correctly_typed_overrides_for_tentative_return_type_interfaces(): void
    {
        $generated = (new ClassGenerator)->generate(ArrayAccessInterface::class);

        $returnType = (new \ReflectionMethod($generated, 'offsetExists'))->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('bool', (string) $returnType);
    }

    /**
     * Caching regression: a measured microbenchmark found every generate()
     * call eval()'d a brand-new class, permanently, for the life of the
     * process — ~14.75KB per call for a 10-method interface, ~120KB per call
     * for a 122-method class, zero reuse. Mirroring Mockery's own
     * CachingGenerator, repeated calls for the same target now return the
     * same already-declared class instead of generating a fresh one each
     * time.
     */
    public function test_repeated_calls_to_generate_reuse_the_same_class_for_the_same_target(): void
    {
        $generator = new ClassGenerator;

        $first = $generator->generate(BookRepositoryInterface::class);
        $second = $generator->generate(BookRepositoryInterface::class);

        $this->assertSame($first, $second);
        $this->assertTrue(class_exists($first));
    }

    public function test_generate_still_produces_a_distinct_class_per_distinct_target(): void
    {
        $generator = new ClassGenerator;

        $repository = $generator->generate(BookRepositoryInterface::class);
        $logger = $generator->generate(ConcreteLogger::class);

        $this->assertNotSame($repository, $logger);
    }

    /**
     * The cache key is the sorted target list (see ClassGenerator::cacheKey()'s
     * own docblock) precisely so a structurally-identical intersection request
     * in a different argument order reuses the same generated class rather
     * than generating a second, functionally-equivalent one.
     */
    public function test_repeated_intersection_calls_reuse_the_same_class_regardless_of_target_order(): void
    {
        $generator = new ClassGenerator;

        $first = $generator->generateForIntersection([Fillable::class, Sized::class]);
        $second = $generator->generateForIntersection([Sized::class, Fillable::class]);

        $this->assertSame($first, $second);
    }

    public function test_rejects_a_non_existent_target(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('no such class or interface exists');

        (new ClassGenerator)->generate('JMac\Testing\Tests\Support\NoSuchThing');
    }

    public function test_rejects_a_final_class(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage("it's final");

        (new ClassGenerator)->generate(FinalLogger::class);
    }

    /**
     * Regression check: a readonly class's properties must stay readonly in
     * every subclass, or PHP refuses the subclass outright with an
     * uncatchable fatal error out of the eval()'d source ("Non-readonly
     * class ... cannot extend readonly class ..."). The generated double
     * never declares a property of its own, so marking it `readonly` too
     * imposes no real constraint — ClassGenerator does this automatically
     * whenever the target itself is readonly.
     */
    public function test_generates_a_readonly_subclass_of_a_readonly_class(): void
    {
        $generated = (new ClassGenerator)->generate(ReadOnlyLogger::class);

        $this->assertTrue(is_subclass_of($generated, ReadOnlyLogger::class));
        $this->assertTrue((new \ReflectionClass($generated))->isReadOnly());
    }

    /**
     * Regression check: an interface's methods are all implicitly abstract,
     * including static ones — before assertNoAbstractStaticMethods()
     * existed, this crashed with an uncatchable PHP fatal error out of the
     * eval()'d source ("must therefore be declared abstract or implement
     * the remaining methods") instead of the library's own exception.
     */
    public function test_rejects_a_target_with_an_abstract_static_method(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('static method (`make`)');

        (new ClassGenerator)->generate(StaticMethodInterface::class);
    }

    /**
     * A static method on a concrete class already has an implementation to
     * fall back on — the generated class simply inherits it unoverridden
     * (see overridableMethods()'s docblock), so this is not the same
     * rejection as the abstract case above.
     */
    public function test_does_not_reject_a_concrete_class_with_a_static_method(): void
    {
        $generated = (new ClassGenerator)->generate(HasStaticMethod::class);

        $this->assertTrue(class_exists($generated));
    }

    /**
     * Regression check: an interface's __call() is abstract like any
     * other method it declares — before assertNoAbstractMagicMethods()
     * existed, this crashed with the identical uncatchable PHP fatal error
     * as the abstract-static-method case above ("must therefore be declared
     * abstract or implement the remaining methods"), just for a magic
     * method instead of a static one. __call is a dynamic-dispatch magic
     * method (see ClassGenerator::DOUBLEABLE_MAGIC_METHODS), so it stays
     * rejected — unlike __invoke below.
     */
    public function test_rejects_a_target_with_an_abstract_magic_method(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('magic method (`__call`)');

        (new ClassGenerator)->generate(MagicMethodInterface::class);
    }

    /**
     * A magic method on a concrete class already has an implementation to
     * fall back on — the generated class simply inherits it unoverridden
     * (see overridableMethods()'s docblock), so this is not the same
     * rejection as the abstract case above.
     */
    public function test_does_not_reject_a_concrete_class_with_a_magic_method(): void
    {
        $generated = (new ClassGenerator)->generate(HasMagicMethod::class);

        $this->assertTrue(class_exists($generated));
    }

    /**
     * __invoke is in ClassGenerator::DOUBLEABLE_MAGIC_METHODS — a fixed,
     * reflectable signature, not dynamic dispatch — so an abstract one on an
     * interface is overridden like any other abstract method instead of
     * being rejected the way abstract __call is above.
     */
    public function test_does_not_reject_an_interface_with_an_abstract_invoke_method(): void
    {
        $generated = (new ClassGenerator)->generate(InvokableInterface::class);

        $this->assertTrue(class_exists($generated));
    }

    /**
     * __invoke on a concrete class is overridden, not just inherited —
     * unlike the still-blocked __call case above, so calling the double
     * runs the proxy, not HasInvokeMethod's real implementation.
     */
    public function test_overrides_invoke_on_a_concrete_class(): void
    {
        $double = Double::for(HasInvokeMethod::class);
        $double->allows('__invoke')->returns('doubled');

        $this->assertSame('doubled', $double(1));
    }

    /**
     * Regression check: PHP 8.4's property hooks let an interface require a
     * hooked property the same way it can require a method — PHP treats an
     * unimplemented hook internally almost like a synthetic abstract method
     * for this purpose, confirmed directly: the crash names it
     * `HookedPropertyInterface::$displayName::get`. Same failure category as
     * the abstract-static/abstract-magic-method crashes above, for a third,
     * unrelated reason a member ends up excluded from overriding —
     * ClassGenerator never reasons about properties at all.
     *
     * #[RequiresPhp] rather than an unconditional test: property-hook syntax
     * (`{ get; }`) is a parser-level PHP 8.4+ feature, not just a runtime
     * API — the fixture file would be a hard parse error if this library's
     * 8.3 floor ever tried to load it. Since PHP only parses a file when
     * it's actually require()'d, and autoloading is lazy, skipping this
     * test on <8.4 means the fixture is never touched there at all.
     */
    #[RequiresPhp('>=8.4.0')]
    public function test_rejects_a_target_with_an_abstract_property_hook(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('hooked property (`displayName`)');

        (new ClassGenerator)->generate(HookedPropertyInterface::class);
    }

    /**
     * A hooked property on a concrete class already has a real
     * implementation to fall back on — the generated class simply inherits
     * it unoverridden (ClassGenerator never touches properties at all), so
     * this is not the same rejection as the abstract case above.
     */
    #[RequiresPhp('>=8.4.0')]
    public function test_does_not_reject_a_concrete_class_with_a_hooked_property(): void
    {
        $generated = (new ClassGenerator)->generate(HasHookedProperty::class);

        $this->assertTrue(class_exists($generated));
    }

    public function test_generates_a_class_implementing_multiple_target_interfaces(): void
    {
        $generated = (new ClassGenerator)->generateForIntersection([Fillable::class, Sized::class]);

        $this->assertTrue(is_subclass_of($generated, Fillable::class));
        $this->assertTrue(is_subclass_of($generated, Sized::class));
    }

    public function test_intersection_rejects_a_non_existent_target(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('no such class or interface exists');

        (new ClassGenerator)->generateForIntersection([Fillable::class, 'JMac\Testing\Tests\Support\NoSuchThing']);
    }

    public function test_intersection_rejects_a_concrete_class_among_the_targets(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage("it's a class");

        (new ClassGenerator)->generateForIntersection([Fillable::class, ConcreteLogger::class]);
    }

    public function test_intersection_rejects_the_same_target_passed_twice(): void
    {
        $this->expectException(InvalidDoubleTargetException::class);
        $this->expectExceptionMessage('passed more than once');

        (new ClassGenerator)->generateForIntersection([Fillable::class, Fillable::class]);
    }

    public static function reservedNameFixtures(): iterable
    {
        yield 'expects' => [ExpectsCollisionInterface::class, 'expects'];
        yield 'allows' => [AllowsCollisionInterface::class, 'allows'];
        yield 'strict' => [StrictCollisionInterface::class, 'strict'];
        yield 'passthru' => [PassthruCollisionInterface::class, 'passthru'];
        yield 'received' => [ReceivedCollisionInterface::class, 'received'];
        yield 'unused' => [UnusedCollisionInterface::class, 'unused'];
        yield 'verify' => [VerifyCollisionInterface::class, 'verify'];
        yield 'AuthorizerInterface allows()' => [AuthorizerInterface::class, 'allows'];

        // A protected reserved-name method still collides — the generated
        // class must widen it to public to satisfy DoubleInterface, which
        // PHP would otherwise reject as an uncatchable fatal. Same shape as
        // Illuminate\Support\LazyCollection's own protected passthru().
        yield 'protected passthru()' => [ProtectedPassthruCollision::class, 'passthru'];
    }

    #[DataProvider('reservedNameFixtures')]
    public function test_rejects_a_target_declaring_a_reserved_control_method_name(string $target, string $method): void
    {
        try {
            (new ClassGenerator)->generate($target);
            $this->fail('Expected ReservedNameCollisionException to be thrown.');
        } catch (ReservedNameCollisionException $exception) {
            $this->assertStringContainsString($target, $exception->getMessage());
            $this->assertStringContainsString($method, $exception->getMessage());
        }
    }

    public function test_never_emits_an_implicit_nullable_parameter_signature(): void
    {
        $generated = (new ClassGenerator)->generate(NullableParamInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'greet'))->getParameters()[0];

        $this->assertTrue($parameter->allowsNull());
        $this->assertSame('?string', (string) $parameter->getType());
    }

    public function test_generated_methods_with_explicit_nullable_parameters_trigger_no_deprecation(): void
    {
        $instance = Double::for(NullableParamInterface::class);

        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;

            return true;
        }, E_DEPRECATED);

        try {
            $instance->allows('greet')->returns('hi');
            $instance->greet();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }

    public function test_does_not_add_a_spurious_nullable_marker_to_a_genuinely_untyped_parameter(): void
    {
        $generated = (new ClassGenerator)->generate(NullableParamInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'untypedDefaultNull'))->getParameters()[0];

        $this->assertNull($parameter->getType());
        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertNull($parameter->getDefaultValue());
    }

    public function test_supports_union_typed_parameters_and_returns(): void
    {
        $instance = Double::for(UnionTypeInterface::class);

        $instance->allows('accept')->returns('ok');

        $this->assertSame('ok', $instance->accept(123));
    }

    /**
     * Behavioral-only coverage (call it, check the return value) can't catch
     * a codegen bug that doesn't happen to crash — see the tentative-return-
     * type bug this same audit already found in ArrayAccess, which passed
     * every existing behavioral test right up until something reflected the
     * actual generated type. This asserts the reconstructed signature text
     * directly instead. Note the member order: PHP's own Reflection API
     * doesn't preserve declaration order for a union type (`int|string`
     * reflects back as `string|int`) — asserted here as the verified actual
     * output, not a guess at what "should" come back.
     */
    public function test_reconstructs_union_type_signatures_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(UnionTypeInterface::class);

        $accept = new \ReflectionMethod($generated, 'accept');
        $this->assertSame('string|int', (string) $accept->getParameters()[0]->getType());
        $this->assertSame('string|int', (string) $accept->getReturnType());
    }

    /**
     * acceptNullableUnion() exists on the fixture specifically for the
     * "null as one member of a union" shape, but until now nothing actually
     * called it or reflected its signature — a fixture method that only
     * looked covered because a sibling method on the same interface was
     * tested. Covers both: the reconstructed signature text and that the
     * interception layer actually works end-to-end for this shape.
     */
    public function test_reconstructs_and_executes_a_nullable_union_type_signature(): void
    {
        $generated = (new ClassGenerator)->generate(UnionTypeInterface::class);

        $method = new \ReflectionMethod($generated, 'acceptNullableUnion');
        $this->assertSame('string|int|null', (string) $method->getParameters()[0]->getType());
        $this->assertSame('string|int|null', (string) $method->getReturnType());

        $instance = Double::for(UnionTypeInterface::class);
        $instance->allows('acceptNullableUnion')->returns(null);

        $this->assertNull($instance->acceptNullableUnion(null));
    }

    public function test_supports_variadic_parameters(): void
    {
        $instance = Double::for(VariadicInterface::class);

        $instance->allows('combine')->returns('a-b-c');

        $this->assertSame('a-b-c', $instance->combine('-', 'a', 'b', 'c'));
    }

    /**
     * Same reasoning as the union-type signature test above: calling
     * combine() and checking its output never actually confirmed the
     * generated parameter is variadic, or reconstructed with the right
     * underlying type — just that the interception plumbing forwards
     * whatever arguments arrive, which would look identical whether or not
     * the "..." was preserved at all.
     */
    public function test_reconstructs_variadic_parameter_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(VariadicInterface::class);

        $parameters = (new \ReflectionMethod($generated, 'combine'))->getParameters();

        $this->assertTrue($parameters[1]->isVariadic());
        $this->assertSame('string', (string) $parameters[1]->getType());
    }

    /**
     * IntersectionReturnInterface::make() is only ever exercised today
     * through Loose mode's *fabrication* tests (LooseModeTest) — a
     * different subsystem (SafeDefaultResolver) confirming the fabricated
     * value satisfies both interfaces, not confirming ClassGenerator's own
     * stringifyIntersectionType() reconstructed the override's declared
     * return type correctly in the first place.
     */
    public function test_reconstructs_intersection_return_type_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(IntersectionReturnInterface::class);

        $returnType = (new \ReflectionMethod($generated, 'make'))->getReturnType();

        $this->assertSame(
            Fillable::class.'&'.Sized::class,
            (string) $returnType,
        );
    }

    /**
     * buildParameter()'s isPassedByReference() handling had zero coverage —
     * not even a fixture declaring a by-reference parameter existed.
     */
    public function test_reconstructs_a_by_reference_parameter_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(ByRefParamInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'increment'))->getParameters()[0];

        $this->assertTrue($parameter->isPassedByReference());
        $this->assertSame('int', (string) $parameter->getType());
    }

    /**
     * Calling a by-ref-parameter method through the double doesn't crash —
     * ProxyBehavior::intercept() receives func_get_args() as ordinary
     * values, so a configured return still works even though the
     * interception layer has no way to write back through the reference.
     * That's a real, accepted limitation (this library configures return
     * values, not out-parameter mutation), not something this test claims
     * to solve — it only proves the generated signature is valid PHP that
     * can actually be called.
     */
    public function test_calls_a_method_with_a_by_reference_parameter_without_error(): void
    {
        $instance = Double::for(ByRefParamInterface::class);
        $instance->allows('increment')->returns(null);

        $value = 5;
        $instance->increment($value);

        $this->addToAssertionCount(1);
    }

    /**
     * Regression check: before ClassGenerator emitted a matching leading
     * "&", doubling a target with a by-reference-returning method
     * (`function &foo()`) crashed with an uncatchable PHP fatal error
     * ("Declaration ... must be compatible with & ...") — the identical
     * failure category as the abstract-static/abstract-magic-method
     * crashes elsewhere in this file, for a third, unrelated reason a
     * signature can mismatch.
     */
    public function test_reconstructs_a_by_reference_return_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(RefReturnInterface::class);

        $this->assertTrue((new \ReflectionMethod($generated, 'getRef'))->returnsReference());
    }

    /**
     * Fixing the crash above (see test_reconstructs_a_by_reference_return_signature_exactly)
     * introduced its own separate issue if the method body weren't built
     * carefully: `return $call;` directly from a by-ref-declared method
     * triggers "Only variable references should be returned by reference"
     * on every call, since ProxyBehavior::intercept()'s own result isn't
     * itself a reference. Confirmed silent, and that the configured value
     * still comes through correctly.
     */
    public function test_calls_a_by_reference_returning_method_without_notice(): void
    {
        $instance = Double::for(RefReturnInterface::class);
        $instance->allows('getRef')->returns(5);

        $notices = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$notices): bool {
            $notices[] = $errstr;

            return true;
        }, E_NOTICE | E_WARNING);

        try {
            $result = $instance->getRef();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $notices);
        $this->assertSame(5, $result);
    }

    /**
     * Regression check: SelfTypedParamBase::withSelf() declares a "self"
     * parameter, inherited unoverridden by SelfTypedParamChild — the same
     * shape as Illuminate\Database\Eloquent\Model::newPivot(self $parent, ...).
     * Left as the literal keyword "self", it would resolve inside the
     * generated class's own body to the generated class itself, which is
     * narrower than the original parameter type — PHP rejects that as an
     * incompatible override with an uncatchable fatal error. It must instead
     * resolve to SelfTypedParamBase, the class that actually declared it.
     */
    public function test_reconstructs_a_self_typed_parameter_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(SelfTypedParamChild::class);

        $parameter = (new \ReflectionMethod($generated, 'withSelf'))->getParameters()[0];

        $this->assertSame(SelfTypedParamBase::class, (string) $parameter->getType());
    }

    /**
     * Same regression, for a "parent" parameter type — must resolve to the
     * declaring class's actual parent (SelfTypedParamGrandparent), not
     * re-resolve against the generated class's own inheritance chain.
     */
    public function test_reconstructs_a_parent_typed_parameter_signature_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(SelfTypedParamChild::class);

        $parameter = (new \ReflectionMethod($generated, 'withParent'))->getParameters()[0];

        $this->assertSame(SelfTypedParamGrandparent::class, (string) $parameter->getType());
    }

    /**
     * Regression check: ConstDefaultBase::withSelfConstant() defaults its
     * parameter to "self::SELF_MODE", inherited unoverridden by
     * ConstDefaultChild — the same shape as
     * Illuminate\Console\OutputStyle::writeln(int $type = self::OUTPUT_NORMAL).
     * Left unqualified against the declaring class, "self" would be
     * backslash-prefixed literally ("\self::SELF_MODE") when re-emitted
     * inside the generated class's own body — an outright "'\self' is an
     * invalid class name" fatal at eval() time, not just an incompatible
     * override. It must instead resolve to ConstDefaultBase, the class that
     * actually declared it.
     */
    public function test_reconstructs_a_self_qualified_constant_default_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(ConstDefaultChild::class);

        $parameter = (new \ReflectionMethod($generated, 'withSelfConstant'))->getParameters()[0];

        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertSame(ConstDefaultBase::SELF_MODE, $parameter->getDefaultValue());
    }

    /**
     * Same regression, for a "parent::"-qualified constant default — must
     * resolve to the declaring class's actual parent (ConstDefaultGrandparent),
     * not re-emit the literal "parent" keyword.
     */
    public function test_reconstructs_a_parent_qualified_constant_default_exactly(): void
    {
        $generated = (new ClassGenerator)->generate(ConstDefaultChild::class);

        $parameter = (new \ReflectionMethod($generated, 'withParentConstant'))->getParameters()[0];

        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertSame(ConstDefaultGrandparent::PARENT_MODE, $parameter->getDefaultValue());
    }

    public function test_preserves_enum_case_default_values(): void
    {
        $generated = (new ClassGenerator)->generate(EnumDefaultInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'draw'))->getParameters()[0];

        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertSame(Suit::Hearts, $parameter->getDefaultValue());
    }

    /**
     * Regression check: Illuminate\Http\Resources\ConditionallyLoadsAttributes::when()
     * declares an untyped `$default = new MissingValue` parameter — PHP 8.1+
     * "new in initializers" syntax. ReflectionParameter::getDefaultValue()
     * evaluates that into a real MissingValue instance, and the old code
     * fed it straight to var_export(), which (for an object with no
     * __set_state()) emits a `Class::__set_state(...)` static method call —
     * not a legal constant expression in a parameter default, and an
     * uncatchable compile-time fatal once the generated source was eval()'d.
     * Doubling this shape must not crash at all.
     */
    public function test_generates_a_class_with_a_new_in_initializer_default_without_crashing(): void
    {
        $generated = (new ClassGenerator)->generate(NewInInitializerParamInterface::class);

        $this->assertTrue(class_exists($generated));
    }

    /**
     * The untyped parameter has no type to widen — its generated override
     * substitutes a plain `null` default and stays untyped, same as any
     * other untyped default (see test_does_not_add_a_spurious_nullable_marker_to_a_genuinely_untyped_parameter()).
     */
    public function test_new_in_initializer_default_on_an_untyped_parameter_becomes_a_bare_null(): void
    {
        $generated = (new ClassGenerator)->generate(NewInInitializerParamInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'untyped'))->getParameters()[1];

        $this->assertNull($parameter->getType());
        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertNull($parameter->getDefaultValue());
    }

    /**
     * The typed parameter's declared type (NewInInitializerDefault) doesn't
     * itself allow null, so substituting `= null` for the unreproducible
     * `new NewInInitializerDefault` default requires widening the generated
     * override's type to `?NewInInitializerDefault` to keep it legal —
     * otherwise this would trade the original fatal for "Default value ...
     * is not compatible with declared type".
     */
    public function test_new_in_initializer_default_on_a_typed_parameter_widens_to_nullable(): void
    {
        $generated = (new ClassGenerator)->generate(NewInInitializerParamInterface::class);

        $parameter = (new \ReflectionMethod($generated, 'typed'))->getParameters()[0];

        $this->assertTrue($parameter->allowsNull());
        $this->assertSame('?'.NewInInitializerDefault::class, (string) $parameter->getType());
        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertNull($parameter->getDefaultValue());
    }

    /**
     * Behavioral coverage to go with the signature-level assertions above:
     * the double is actually callable end-to-end, including with the
     * parameter omitted entirely (exercising the generated default).
     */
    public function test_calls_a_method_with_a_new_in_initializer_default_without_error(): void
    {
        $instance = Double::for(NewInInitializerParamInterface::class);
        $instance->allows('untyped')->returns('ok');

        $this->assertSame('ok', $instance->untyped(true));
    }

    public function test_generated_double_satisfies_type_hints_in_real_collaborators(): void
    {
        $instance = Double::for(BookRepositoryInterface::class);

        $instance->allows('find')->returns(new Book('Some Title'));

        $consumer = new class($instance)
        {
            public function __construct(public readonly BookRepositoryInterface $repository) {}
        };

        $this->assertInstanceOf(BookRepositoryInterface::class, $consumer->repository);
        $this->assertSame('Some Title', $consumer->repository->find(1)?->title);
    }
}
