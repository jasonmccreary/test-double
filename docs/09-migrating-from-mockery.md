# Migrating from Mockery

Most of what you already know from Mockery carries over directly. This page provides a full mapping of the methods and concepts from Mockery to their Double equivalent. For the reasoning behind the differences, see [How is Double better than Mockery?](https://testdoublephp.com/blog/how-is-double-better-than-mockery)

You may [automate the conversion from Mockery to Double](https://laravelshift.com/mockery-test-double-converter) for free with Shift.

## Quick Reference

| Mockery | This Library |
|---|---|
| `Mockery::mock(Foo::class)` | `Double::for(Foo::class)` |
| `Mockery::spy(Foo::class)` | `Double::for(Foo::class)`. Spy-style checking is `received()`, available on every double (see [below](#theres-no-separate-spy)) |
| `Mockery::mock()->shouldIgnoreMissing()` | `Double::for(Foo::class)`. This is simply the default; see [Modes](03-creating-doubles.md#modes) |
| `Mockery::mock(Foo::class, [$args])->shouldDeferMissing()` | `Double::for(Foo::class)->passthru($realInstance)` |
| `Mockery::mock(Foo::class)->makePartial()` | `Double::for(Foo::class)->passthru()`. See [below](#modes-not-mock-kinds) |
| `shouldReceive('foo')->once()->andReturn($x)` | `expects('foo')->returns($x)`. Exactly-once is `expects()`'s default |
| `shouldReceive('foo')->andReturn($x)` | `allows('foo')->returns($x)` |
| `shouldReceive('foo')->andReturn($a, $b)` | `allows('foo')->returns($a, $b)` |
| `shouldReceive('foo')->andThrow($e)` | `allows('foo')->throws($e)` |
| `shouldReceive('foo')->andReturnUsing($fn)` | `allows('foo')->resolves($fn)` |
| `shouldHaveReceived('foo')` | `received('foo')` |
| `shouldNotHaveReceived('foo')` | `received('foo')->never()` |
| `shouldNotHaveBeenCalled()` | `unused()`. See [the trap below](#theres-no-separate-spy) |
| `once()` / `twice()` | `times(1)` / `times(2)` |
| `atLeast()->times($n)` | `times(minimum: $n)` |
| `atMost()->times($n)` | `times(maximum: $n)` |
| `between($min, $max)` | `times($min, $max)` |
| `ordered()` | `ordered()` |
| `globally()` | not available. Ordering applies per double; see [below](#ordering) |
| `byDefault()` | not available. See [below](#a-few-things-that-didnt-carry-over) |
| `Mockery::close()` | `$double->verify()`, or `use VerifiesDoubles;`. See [Test Suite Integration](08-test-suite-integration.md) |

### A Bare `Mockery::mock()` Doesn't Carry Over

`Mockery::mock()` with no class argument creates an object that responds to anything you configure on it, regardless of type. It was common to reuse one of these for two unrelated roles — for example, one bare mock standing in as both a `LockProvider` and the `Lock` it returns from itself:

```php
$lock->shouldReceive('lock')->andReturn($lock);   // lock() returns itself
$lock->shouldReceive('get')->andReturn(true);      // then get() is called on "itself"
```

`Double::for()` always doubles a real type, so it only declares the methods that type actually has. `Double::for(LockProvider::class)` doesn't declare `get()`, so the equivalent won't work — split into two typed doubles instead, matching the real return type of the first call:

```php
$acquiredLock = Double::for(Lock::class);
$lock->expects('lock')->returns($acquiredLock);
$acquiredLock->expects('get')->returns(true);
```

This is more setup than the bare mock needed, but it also matches the real type contract — Mockery's version only worked because it didn't check. See [What about "unnamed mocks"?](https://testdoublephp.com/blog/what-about-unnamed-mocks) for the fuller case against a bare, untyped mock.

### Stubbing Methods Behind `__call()`

Mockery would stub any method name on a mock, whether or not the real class declared it. Double requires a method to be reflectable on the double's target, so this doesn't carry over as-is. It mostly comes up with classes whose public API is entirely `__call()`-forwarded — AWS SDK clients, Redis connection wrappers, and similar. See [Why doesn't Double mock magic methods?](https://testdoublephp.com/blog/why-doesnt-double-mock-magic-methods) for two real examples of what to double instead — and one case where the fix isn't a Double concern at all.

## Argument Matchers

| Mockery | This Library |
|---|---|
| `Mockery::any()` | `Argument::any()` |
| `Mockery::type($type)` | `Argument::type($type)` |
| `Mockery::on($callback)` | `Argument::satisfies($callback)` |
| `Mockery::capture()` | `Argument::capture($reference)` |
| `Mockery::pattern($regex)` | `Argument::matches($regex)` |
| `Mockery::anyOf($a, $b)` | `Argument::any($a, $b)` |
| `Mockery::notAnyOf($a, $b)` | `Argument::not()->any($a, $b)` |
| `Mockery::not($value)` | `Argument::not($value)` |
| `Mockery::contains(...)` / `hasKey(...)` / `hasValue(...)` | `Argument::contains(...)`. See [Searching a Collection](05-argument-matching.md#searching-a-collection) |
| `Mockery::mustBe($value)` / `isEqual($value)` | a plain value passed to `with()`, already the default |
| `Mockery::isSame($value)` | `Argument::same($value)` |
| `Mockery::ducktype(...)` | not available. See [below](#a-few-things-that-didnt-carry-over) |
| `andAnyOtherArgs()` | `Argument::remaining()` |
| `withNoArgs()` | `Argument::none()`, or `with()` with nothing passed. See [No Arguments at All](05-argument-matching.md#no-arguments-at-all) |

### `withArgs(closure)` and Arity

Mockery's `withArgs(function ($a) { ... })` only ever receives as many arguments as the closure declares — a single-parameter closure silently ignores any further actual arguments. `with()` here expects one matcher per actual argument; a converted `Argument::satisfies($closure)` covering only the first parameter fails against a call with more arguments than that, rather than being treated as "don't care" about the rest:

```php
// connectToCluster(array $config, array $clusterOptions, array $options) — 3 params
$connector->expects('connectToCluster')->with(
    Argument::satisfies(fn ($configArg) => $config === $configArg),
    Argument::remaining(), // was implicit in Mockery's single-param closure
);
```

`Argument::remaining()` (see [Trailing Arguments](05-argument-matching.md#trailing-arguments)) is the explicit equivalent of Mockery's implicit "closure took fewer params than the call had arguments" behavior.

If the closure's logic genuinely spans several arguments together — not just "ignore the rest," but a check that needs two or more real values at once — `Argument::all()` (see [Custom Logic Across Every Argument](05-argument-matching.md#custom-logic-across-every-argument)) is the direct equivalent of `withArgs(closure)` itself: it hands the predicate the whole real argument list, the same way Mockery always did.

### Comparison Is Strict, Not Loose

A plain value passed to `with()`/`returns()` is compared with `===`-like strictness. Mockery's default comparison is loose `==`, which for an object argument checked against a string triggers `__toString()` coercion — a `Carbon` instance and a date string can compare equal under Mockery even though they're different types. Double never does this coercion; the two are simply unequal.

Converting a suite from Mockery can surface real, previously-silent bugs this way: an expectation that "passed" for years under `==` may legitimately fail under Double because it was never actually checking what it looked like it was checking. If that happens, look at what's actually being compared before assuming the conversion introduced the problem — a `->with()` value that reads as a string may need `Argument::satisfies()` with an explicit cast if the real call genuinely passes an object.

## Modes, Not Mock Kinds

Mockery starts with a choice: a mock, a spy, or a partial mock. Here, there's one kind of thing (a double), and the equivalent choice is a mode you add on top of it, covered fully in [Creating Doubles](03-creating-doubles.md):

- Mockery's plain `mock()`, once you call `shouldIgnoreMissing()`, behaves like **Loose** mode here, which is simply the default, nothing to opt into.
- A strict `mock()` with no leniency maps to **Strict** mode (`->strict()`).
- `shouldDeferMissing()` and `makePartial()` both map to **Passthru** mode (`->passthru()`) — Mockery splits these into two constructor calls, Double doesn't need to: it's the same mode either way, just with or without an argument.
  - `shouldDeferMissing()`, which takes real constructor arguments (or, in Mockery terms, is typically paired with `Mockery::mock(Foo::class, [$args])`), maps to `->passthru($realInstance)` — you build the real object however you need to (with real dependencies, a container, whatever), and hand it in directly. This is actually more flexible than Mockery here, since you're not limited to constructor arguments — you can hand in any already-built object.
  - `makePartial()`, which takes nothing, maps to `->passthru()` with no argument — the double builds its own real state via the target's constructor.
  - Either way, an unstubbed method runs its real code *on the double itself*, so a self-call it makes internally to another method of the same object can also hit a configured stub — see [Passthru](03-creating-doubles.md#passthru) for why that matters and what it costs.

### There's No Separate "Spy"

In Mockery, `spy()` is its own constructor. Here, `received()` (checking whether something was actually called) is available on every double, regardless of how it was created or which mode it's in. You don't choose a "spy" up front; you reach for `received()` whenever you want to check after the fact, on the same double you'd otherwise configure with `expects()`/`allows()`. See [Verification](06-verification.md), and [Why not `hasReceived()` or `assertReceived()`?](https://testdoublephp.com/blog/why-not-hasreceived-or-assertreceived) for why the verb has no prefix.

Watch out for `shouldNotHaveBeenCalled()` specifically: it reads like "this spy received no calls," but Mockery only checks whether the mock was invoked as a callable. It says nothing about calls to its methods, which is what most people actually mean and expect it to check. That's the trap `unused()` exists to close: it asserts the double received zero calls to any method, which is almost certainly what you meant in the first place.

## Ordering

Mockery orders calls per-mock by default, with `globally()` available for a single sequence shared across every mock in a test. This library keeps the per-double default and doesn't offer a global equivalent. If you find yourself needing a sequence that spans multiple doubles, it's worth pausing to consider whether the test is asserting more about call order than the behavior actually requires.

## A Few Things That Didn't Carry Over

A couple of Mockery features aren't available here, by design:

- **Aliases.** If you're used to `shouldReceive()`, `andReturn()`, or other alternate spellings for the same concept, those don't exist here. Each concept has exactly one verb. See [One Clean API](01-introduction.md#one-clean-api).
- **`ducktype()`.** Matching "anything with these methods" isn't included. `Argument::satisfies()` covers the same need without a dedicated verb.
- **Static method mocking** (Mockery's `alias:` mocks). Mockery's own documentation already treats this as a last resort, and this library doesn't attempt to improve on it. See [Static Methods](04-expectations.md#static-methods).
- **`byDefault()`.** Marking an expectation as a fallback that a later, more specific one can override. For the common case (an unbounded fallback, `allows()` with no `times()`/minimum), just register the fallback first and the override second: expectations are matched most-recently-registered first, falling back to earlier ones when a later one's `with()` constraints don't match the actual call, or its own `times()` budget is exhausted. What doesn't carry over is Mockery's eviction behavior: a `byDefault()` expectation with its own call-count requirement has that requirement silently dropped once any other expectation exists for the method, even if that other expectation never actually matches a call. Here, every registered expectation's minimum stays in force and is checked at `verify()`.
