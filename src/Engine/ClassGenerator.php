<?php

declare(strict_types=1);

namespace JMac\Testing\Engine;

use JMac\Testing\DoubleInterface;
use JMac\Testing\Exceptions\InvalidDoubleTargetException;
use JMac\Testing\Exceptions\ReservedNameCollisionException;

/**
 * @internal
 *
 * Reflects a target class/interface and eval()s a new class that either
 * extends or implements it, overriding every overridable method to funnel
 * through ProxyBehavior::intercept().
 */
final class ClassGenerator
{
    private const RESERVED_METHODS = ['expects', 'allows', 'strict', 'passthru', 'received', 'unused', 'verify'];

    /**
     * Magic methods with a fixed, reflectable signature — a real single entry
     * point, not PHP's dynamic-dispatch mechanism for members that don't
     * exist (__get/__set/__isset/__unset/__call/__callStatic, which stay
     * blocked). These get treated like any other method: overridden by
     * ClassGenerator and configurable via Double::expects()/allows().
     *
     * __set_state isn't listed despite having a fixed signature — it's
     * always static, so it's already excluded by the static-method check
     * everywhere this list is consulted, making a magic-method carve-out for
     * it moot.
     */
    public const DOUBLEABLE_MAGIC_METHODS = ['__invoke', '__toString', '__serialize', '__unserialize', '__clone'];

    private static int $counter = 0;

    /**
     * A second call to generate()/generateForIntersection() for the same
     * target(s) returns the already-declared class instead of eval()ing a
     * fresh one. Uncached, this cost ~1-1.5KB of process memory per public
     * method on the target, per call, permanently.
     *
     * @var array<string, string> cache key (see cacheKey()) => already-declared generated class name
     */
    private static array $cache = [];

    public function generate(string $target): string
    {
        if (! class_exists($target) && ! interface_exists($target)) {
            throw InvalidDoubleTargetException::doesNotExist($target);
        }

        $reflection = new \ReflectionClass($target);

        // Reflects whatever PHP actually compiled, not the source on disk —
        // `final` is permanent for the rest of the process once a class is
        // loaded, unless Double::bypassFinals() rewrote it out of the source
        // before this was the thing that first triggered loading it (see
        // FinalBypass). Nothing short of that changes what this check sees,
        // which is why the exception below names that escape hatch directly
        // instead of leaving a dead end.
        if ($reflection->isFinal()) {
            throw InvalidDoubleTargetException::isFinal($target);
        }

        $keyword = $reflection->isInterface() ? 'implements' : 'extends';

        return $this->generateFromReflections([$reflection], [$target], $keyword);
    }

    /**
     * @internal Used by SafeDefaultResolver (fabricating an
     * intersection-typed return) and Double::for() (a direct
     * multi-target double). Intersection members are always interfaces in
     * PHP, so this validates every target actually is one instead of
     * branching between extends/implements.
     *
     * @param  list<string>  $targets
     */
    public function generateForIntersection(array $targets): string
    {
        $this->assertValidIntersectionTargets($targets);

        $reflections = array_map(
            static fn (string $target): \ReflectionClass => new \ReflectionClass($target),
            $targets,
        );

        return $this->generateFromReflections($reflections, $targets, 'implements');
    }

    /**
     * @param  list<string>  $targets
     */
    private function assertValidIntersectionTargets(array $targets): void
    {
        $seen = [];

        foreach ($targets as $target) {
            if (isset($seen[$target])) {
                throw InvalidDoubleTargetException::duplicateTarget($target);
            }

            $seen[$target] = true;

            if (interface_exists($target)) {
                continue;
            }

            throw class_exists($target)
                ? InvalidDoubleTargetException::mustBeInterface($target)
                : InvalidDoubleTargetException::doesNotExist($target);
        }
    }

    /**
     * @param  list<\ReflectionClass>  $reflections
     * @param  list<string>  $targets
     */
    private function generateFromReflections(array $reflections, array $targets, string $keyword): string
    {
        $cacheKey = $this->cacheKey($targets);

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $this->assertNoReservedNameCollisions($targets, $reflections);
        $this->assertNoAbstractStaticMethods($targets, $reflections);
        $this->assertNoAbstractMagicMethods($targets, $reflections);
        $this->assertNoAbstractPropertyHooks($targets, $reflections);

        $className = $this->generateClassName($reflections);

        eval($this->buildSource($className, $keyword, $targets, $reflections));

        return self::$cache[$cacheKey] = $className;
    }

    /**
     * Sorted, not positional — generateForIntersection() merges overridable
     * methods across targets regardless of argument order, so `[A, B]` and
     * `[B, A]` should share one cached class rather than generating twice.
     *
     * @param  list<string>  $targets
     */
    private function cacheKey(array $targets): string
    {
        $sorted = $targets;
        sort($sorted);

        return implode('&', $sorted);
    }

    /**
     * @param  list<string>  $targets
     * @param  list<\ReflectionClass>  $reflections
     */
    private function assertNoReservedNameCollisions(array $targets, array $reflections): void
    {
        $declared = [];

        foreach ($reflections as $reflection) {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $declared[] = $method->getName();
            }
        }

        $collisions = array_values(array_intersect(self::RESERVED_METHODS, $declared));

        if ($collisions !== []) {
            throw ReservedNameCollisionException::forCollisions(implode('&', $targets), $collisions);
        }
    }

    /**
     * A concrete static method is inherited unoverridden (harmless — real
     * code, just not interceptable). An abstract one would leave the
     * generated `final class` not actually implementing it — caught here,
     * before eval(), instead of surfacing as an uncatchable fatal error
     * from inside the eval()'d source.
     *
     * @param  list<string>  $targets
     * @param  list<\ReflectionClass>  $reflections
     */
    private function assertNoAbstractStaticMethods(array $targets, array $reflections): void
    {
        foreach ($reflections as $reflection) {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->isStatic() && $method->isAbstract()) {
                    throw InvalidDoubleTargetException::hasAbstractStaticMethod(implode('&', $targets), $method->getName());
                }
            }
        }
    }

    /**
     * Same failure shape as assertNoAbstractStaticMethods() above, for magic
     * methods (anything starting with "__") instead of static ones — except
     * DOUBLEABLE_MAGIC_METHODS, which overridableMethods() does override, so
     * an abstract one there is exactly as fine as any other abstract public
     * method. Runs after the static check, so something both static and
     * magic (__callStatic, in practice) is already caught there first.
     *
     * @param  list<string>  $targets
     * @param  list<\ReflectionClass>  $reflections
     */
    private function assertNoAbstractMagicMethods(array $targets, array $reflections): void
    {
        foreach ($reflections as $reflection) {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                if (str_starts_with($method->getName(), '__')
                    && ! in_array($method->getName(), self::DOUBLEABLE_MAGIC_METHODS, true)
                    && $method->isAbstract()
                ) {
                    throw InvalidDoubleTargetException::hasAbstractMagicMethod(implode('&', $targets), $method->getName());
                }
            }
        }
    }

    /**
     * Same failure shape again, for PHP 8.4+ hooked properties
     * (`public string $name { get; }`) — this generator never reasons about
     * properties otherwise, only methods.
     *
     * @param  list<string>  $targets
     * @param  list<\ReflectionClass>  $reflections
     */
    private function assertNoAbstractPropertyHooks(array $targets, array $reflections): void
    {
        // ReflectionProperty::isAbstract() doesn't exist before PHP 8.4 (this
        // library's floor is 8.3) — load-bearing, not defensive dead code: on
        // 8.3 a property can't be abstract at all yet.
        if (! method_exists(\ReflectionProperty::class, 'isAbstract')) {
            return;
        }

        foreach ($reflections as $reflection) {
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $property) {
                if ($property->isAbstract()) {
                    throw InvalidDoubleTargetException::hasAbstractPropertyHook(implode('&', $targets), $property->getName());
                }
            }
        }
    }

    /**
     * @param  list<\ReflectionClass>  $reflections
     */
    private function generateClassName(array $reflections): string
    {
        $short = implode('_', array_map(
            static fn (\ReflectionClass $reflection): string => preg_replace('/[^A-Za-z0-9_]/', '_', $reflection->getShortName()),
            $reflections,
        ));

        return sprintf('JMac\Testing\\Engine\\Generated\\%s_%d', $short, ++self::$counter);
    }

    /**
     * @param  list<string>  $targets
     * @param  list<\ReflectionClass>  $reflections
     */
    private function buildSource(string $fqcn, string $keyword, array $targets, array $reflections): string
    {
        $position = strrpos($fqcn, '\\');
        $namespace = substr($fqcn, 0, $position);
        $shortName = substr($fqcn, $position + 1);

        $parents = implode(', ', array_map(
            static fn (string $target): string => '\\'.ltrim($target, '\\'),
            $targets,
        ));

        // Every generated double implements DoubleInterface for real, not just as
        // a docblock fiction for Double::for()'s @template/@return pairing. A
        // single-class target uses `extends`, so the interface needs its own
        // `implements` clause; an interface target already uses `implements`, so it
        // just joins the list.
        $controlInterface = '\\'.ltrim(DoubleInterface::class, '\\');
        $inheritance = $keyword === 'extends'
            ? sprintf('extends %s implements %s', $parents, $controlInterface)
            : sprintf('implements %s, %s', $parents, $controlInterface);

        $overridable = $this->overridableMethods($reflections);

        $methods = implode("\n", array_map($this->buildMethod(...), $overridable));

        // Inline passthru (see DoubleControlMethods::passthru()) needs a way to
        // run a method's real, inherited body directly on the double itself —
        // `parent::{$method}(...)` only compiles inside a class that actually
        // has a parent, so this is skipped entirely for an `implements` target
        // (an interface has no body to run). Every overridable method gets one
        // of these regardless of whether inline passthru is ever used on a
        // given double — cheap to generate, and simpler than threading "will
        // this double ever need it" through class generation.
        if ($keyword === 'extends') {
            $methods .= "\n".implode("\n", array_map($this->buildRealMethod(...), $overridable));
        }

        // A class extending a readonly one must be readonly itself (every
        // property on a readonly class stays readonly all the way down the
        // hierarchy, so PHP refuses a non-readonly subclass outright). Only
        // relevant for `extends` — interfaces can't be readonly, so an
        // `implements` target (single interface or intersection) never
        // triggers this. Harmless either way: the generated class never
        // declares a property of its own, so marking it readonly imposes no
        // real constraint here.
        $readOnly = $keyword === 'extends' && $reflections[0]->isReadOnly() ? 'readonly ' : '';

        return sprintf(
            "namespace %s;\n\n%sfinal class %s %s\n{\n    use \\%s;\n\n%s\n}\n",
            $namespace,
            $readOnly,
            $shortName,
            $inheritance,
            DoubleControlMethods::class,
            $methods,
        );
    }

    /**
     * Merges overridable methods across every target, keyed by name — the
     * first reflection to declare a given method wins.
     *
     * @param  list<\ReflectionClass>  $reflections
     * @return list<\ReflectionMethod>
     */
    private function overridableMethods(array $reflections): array
    {
        $methods = [];

        foreach ($reflections as $reflection) {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                if (isset($methods[$method->getName()])) {
                    continue;
                }

                // Static excluded: ProxyBehavior::intercept() takes $this and
                // dispatches through it, and a static call has no $this to give it.
                // A concrete static method is just inherited unoverridden; an
                // abstract one is already caught by assertNoAbstractStaticMethods().
                if ($method->isFinal()
                    || $method->isStatic()
                    || $method->isConstructor()
                    || $method->isDestructor()
                    || (str_starts_with($method->getName(), '__') && ! in_array($method->getName(), self::DOUBLEABLE_MAGIC_METHODS, true))
                ) {
                    continue;
                }

                $methods[$method->getName()] = $method;
            }
        }

        return array_values($methods);
    }

    private function buildMethod(\ReflectionMethod $method): string
    {
        $call = sprintf(
            '\\%s::intercept($this, %s, func_get_args())',
            ProxyBehavior::class,
            var_export($method->getName(), true),
        );

        return $this->buildMethodFromCall($method, $method->getName(), $call);
    }

    /**
     * Inline passthru's real-body counterpart to buildMethod() above: same
     * signature, but the body runs the method's actual inherited
     * implementation via `parent::` instead of funneling through
     * ProxyBehavior::intercept(). Named with a "__td_real_" prefix, never
     * exposed as part of the double's own configurable API — see
     * ProxyBehavior::handleUnmatchedCall()'s inline-passthru branch, the only
     * caller.
     */
    private function buildRealMethod(\ReflectionMethod $method): string
    {
        $name = $method->getName();
        $forwarded = implode(', ', array_map(
            static fn (\ReflectionParameter $parameter): string => ($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName(),
            $method->getParameters(),
        ));

        $call = sprintf('parent::%s(%s)', $name, $forwarded);

        return $this->buildMethodFromCall($method, '__td_real_'.$name, $call);
    }

    /**
     * Shared signature-building for buildMethod() and buildRealMethod() —
     * both need the exact same visibility, parameters, and return type, and
     * differ only in $overrideName and what expression $call evaluates.
     */
    private function buildMethodFromCall(\ReflectionMethod $method, string $overrideName, string $call): string
    {
        $visibility = $method->isProtected() ? 'protected' : 'public';
        $declaringClass = $method->getDeclaringClass();

        $parameters = implode(', ', array_map(
            fn (\ReflectionParameter $parameter): string => $this->buildParameter($parameter, $declaringClass),
            $method->getParameters(),
        ));

        // getReturnType() is null for a method whose only declared return type is
        // "tentative" — the mechanism built-in interfaces like ArrayAccess and
        // Countable use while a return type is still being phased in as enforced.
        // Falling back to getTentativeReturnType() stops the override from being
        // built with no return type at all, which PHP flags as a deprecated
        // incompatibility against the interface's tentative one.
        $returnType = $method->getReturnType() ?? ($method->hasTentativeReturnType() ? $method->getTentativeReturnType() : null);
        $returnTypeString = $returnType !== null ? $this->stringifyType($returnType, $declaringClass) : null;
        $returnDeclaration = $returnTypeString !== null ? ': '.$returnTypeString : '';
        $isVoid = $returnTypeString === 'void';

        // A by-reference-returning method (`function &foo()`) requires the override to
        // declare the same leading "&", or PHP rejects it as incompatible at eval()
        // time. Once declared by-reference, `return $call;` directly also fails —
        // "Only variable references should be returned by reference," since
        // the call's result isn't itself a reference — so assigning to a local
        // variable first is what makes the by-ref return actually silent.
        $reference = $method->returnsReference() ? '&' : '';
        $body = match (true) {
            $reference !== '' => sprintf("\$__td_result = %s;\n\n        return \$__td_result;", $call),
            $isVoid => sprintf('%s;', $call),
            default => sprintf('return %s;', $call),
        };

        return sprintf(
            "    %s function %s%s(%s)%s\n    {\n        %s\n    }\n",
            $visibility,
            $reference,
            $overrideName,
            $parameters,
            $returnDeclaration,
            $body,
        );
    }

    private function buildParameter(\ReflectionParameter $parameter, \ReflectionClass $declaringClass): string
    {
        $type = $parameter->getType();

        $byRef = $parameter->isPassedByReference() ? '&' : '';
        $variadic = $parameter->isVariadic() ? '...' : '';

        $default = '';
        $forceNullable = false;

        if (! $parameter->isVariadic() && $parameter->isDefaultValueAvailable()) {
            if ($parameter->isDefaultValueConstant()) {
                $default = ' = '.$this->qualifyConstantName($parameter->getDefaultValueConstantName(), $declaringClass);
            } elseif ($this->isReproducibleDefault($value = $parameter->getDefaultValue())) {
                $default = ' = '.var_export($value, true);
            } else {
                // Not a scalar/array/enum-case/resolvable-constant — in valid PHP this can
                // only be a PHP 8.1+ "new in initializers" object default (e.g. `= new
                // MissingValue`), which getDefaultValue() already evaluated into a real
                // instance. var_export() on an arbitrary object without __set_state()
                // emits a static method call, which isn't a legal constant expression in
                // a parameter default — a compile-time fatal once eval()'d. The generated
                // override only ever forwards through func_get_args() anyway (see
                // buildMethod()), so the real default value is never actually needed here:
                // substitute a benign `null` and widen the type to accept it if it doesn't
                // already.
                $default = ' = null';
                $forceNullable = $type !== null && ! $type->allowsNull();
            }
        }

        $typeDeclaration = $type !== null ? $this->stringifyType($type, $declaringClass, $forceNullable).' ' : '';

        return sprintf('%s%s%s$%s%s', $typeDeclaration, $byRef, $variadic, $parameter->getName(), $default);
    }

    /**
     * Whether $value round-trips through var_export() as a legal PHP constant
     * expression: scalars, null, enum cases (var_export() has emitted these as
     * `\Enum::CASE` since PHP 8.1), and arrays composed only of those,
     * recursively. A plain object doesn't have this property — var_export()
     * falls back to `Class::__set_state(...)`, a static method call, which
     * PHP does not allow in a default-value position.
     */
    private function isReproducibleDefault(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->isReproducibleDefault($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value) || $value instanceof \UnitEnum;
    }

    /**
     * The generated class lives in its own namespace, so any class-qualified
     * default (an enum case, a ::class constant) must be forced absolute —
     * otherwise PHP resolves it relative to the generated namespace instead
     * of the one the original signature was written in.
     *
     * "self"/"parent" prefixes are resolved against the declaring class first,
     * same reasoning as stringifyNamedType(): left as literal keywords, they'd
     * re-resolve inside the generated class's own body instead of the class
     * that actually declared the default. Unlike a type, there's no
     * contravariance escape hatch here — "\self::CONST" is simply an invalid
     * class name, an eval()-time fatal rather than a compatibility rejection.
     * "static" is left as a bare keyword (never backslash-qualified) for the
     * same late-static-binding reason stringifyNamedType() leaves it alone.
     */
    private function qualifyConstantName(string $name, \ReflectionClass $declaringClass): string
    {
        if (! str_contains($name, '::')) {
            return $name;
        }

        [$prefix, $const] = explode('::', $name, 2);

        $resolved = match (strtolower($prefix)) {
            'self' => $declaringClass->getName(),
            'parent' => $declaringClass->getParentClass()->getName(),
            'static' => $prefix,
            default => $prefix,
        };

        return $resolved === 'static' || str_starts_with($resolved, '\\')
            ? "{$resolved}::{$const}"
            : "\\{$resolved}::{$const}";
    }

    /**
     * $forceNullable is only ever set by buildParameter() substituting a
     * benign `null` default for an unreproducible one (see
     * isReproducibleDefault()) on a type that didn't already allow null —
     * widening the type to match is what keeps `= null` legal there.
     */
    private function stringifyType(\ReflectionType $type, \ReflectionClass $declaringClass, bool $forceNullable = false): string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $this->stringifyNamedType($type, $declaringClass, $forceNullable);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $intersection = $this->stringifyIntersectionType($type, $declaringClass);

            // Plain intersection types have no nullable-shorthand syntax of their
            // own — DNF (PHP 8.2+) is what makes "(A&B)|null" legal.
            return $forceNullable ? "({$intersection})|null" : $intersection;
        }

        // ReflectionUnionType: members are either ReflectionNamedType or,
        // for a member like (A&B)|C, a nested ReflectionIntersectionType.
        $members = implode('|', array_map(
            fn (\ReflectionType $member): string => $member instanceof \ReflectionIntersectionType
                ? '('.$this->stringifyIntersectionType($member, $declaringClass).')'
                : $this->stringifyNamedType($member, $declaringClass),
            $type->getTypes(),
        ));

        return $forceNullable && ! $type->allowsNull() ? "{$members}|null" : $members;
    }

    private function stringifyIntersectionType(\ReflectionIntersectionType $type, \ReflectionClass $declaringClass): string
    {
        return implode('&', array_map(
            fn (\ReflectionNamedType $member): string => $this->stringifyNamedType($member, $declaringClass),
            $type->getTypes(),
        ));
    }

    /**
     * Mirrors PHP's own ReflectionNamedType::__toString() (leading "?" iff
     * allowsNull() and the type isn't "mixed"/"null" itself) but additionally
     * forces class/interface names absolute with a leading "\", which
     * __toString() does not do — necessary because the generated class lives
     * in its own namespace, not the target's.
     *
     * "self" and "parent" are resolved to the real class they refer to in the
     * method's declaring class, rather than left as literal keywords. Left
     * literal, they'd re-resolve inside the generated class's own body — e.g.
     * "self" on a method declared in a grandparent would become the generated
     * class itself, a narrower type than the original. That's fine for a
     * covariant return type, but PHP rejects it on a parameter, which must
     * stay the same or wider. "static" is left alone: it's return-type-only,
     * so this contravariance problem can't apply, and rewriting it would
     * break the late static binding it exists for.
     */
    private function stringifyNamedType(\ReflectionNamedType $type, \ReflectionClass $declaringClass, bool $forceNullable = false): string
    {
        $name = $type->getName();
        $lower = strtolower($name);

        $name = match ($lower) {
            'self' => $declaringClass->getName(),
            'parent' => $declaringClass->getParentClass()->getName(),
            default => $name,
        };

        if (! $type->isBuiltin() && $lower !== 'static') {
            $name = '\\'.ltrim($name, '\\');
        }

        $nullablePrefix = ($type->allowsNull() || $forceNullable) && ! in_array($lower, ['mixed', 'null'], true) ? '?' : '';

        return $nullablePrefix.$name;
    }
}
