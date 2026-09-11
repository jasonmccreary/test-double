# Creating Doubles

You may create a double for any class or interface using `Double::for()`:

```php
use JMac\Testing\Double;

$repository = Double::for(BookRepository::class);
```

This returns a real object. It satisfies `instanceof BookRepository`, it passes any type hint that expects one, and you may hand it straight to whatever you're testing:

```php
$service = new CatalogService($repository);
```

Nothing about the double is configured yet. See [Expectations](04-expectations.md) for that. This page covers the double itself: how to create one, what it's called in failure messages, and how it behaves before you've told it anything.

## Doubling More Than One Thing at Once

Sometimes the code you're testing needs a single object that satisfies more than one contract. A logger that's also flushable, for example. You may pass more than one target to `for()` to get one double implementing all of them:

```php
$logger = Double::for(LoggerInterface::class, FlushableInterface::class);

$logger instanceof LoggerInterface;    // true
$logger instanceof FlushableInterface; // true
```

Every target after the first must be an interface, the same rule PHP applies to intersection types, since a class may only extend one parent.

## Wrapping a Real Instance

If you already have a real object in hand (built by a factory, resolved from a container, or otherwise), you may pass the instance itself to `for()` instead of a class name:

```php
$double = Double::for($realBook)->passthru();
```

`Double::for($realBook)` doubles `$realBook`'s class and remembers the instance, so a later `->passthru()` call knows what to delegate to without you needing to repeat yourself (`Double::for(Book::class)->passthru($realBook)` would work just as well). More on what `passthru()` does in [Modes](#modes) below.

## Labels

Every double is given a label, derived from its class or interface name. `BookRepository::class` becomes `"BookRepository"`. This label appears in every failure message the double produces (see [Failure Messages](07-failure-messages.md)), so you always know which double is involved without hunting through generated class names.

## Reserved Method Names

Configuration lives directly on the double itself, which is what makes `$repository->expects(...)` possible without a separate builder object. The trade-off is that seven method names are reserved on every double:

```
expects, allows, strict, passthru, received, unused, verify
```

If the class or interface you're doubling declares a real method with one of these names, `Double::for()` throws right away, naming the exact method:

```php
interface AuthorizerInterface
{
    public function allows(string $ability): bool;
}

Double::for(AuthorizerInterface::class);
// Can't create a double for `AuthorizerInterface`. It contains `allows`
// which collides with Double's internal methods.
```

This does come up in practice. `allows()` is part of Laravel's own `Gate` contract, for instance. It's worth knowing about early, rather than discovering it as a confusing test failure later.

### Overriding a Reserved Name Collision

Passing `override: true` lets you double it anyway:

```php
$gate = Double::for(AuthorizerInterface::class, override: true);
```

The object this returns is a genuinely different kind of double. `allows()` already means something real on `AuthorizerInterface` — it can't also mean "configure an expectation" on that same object, so one of the two has to give way. Rather than quietly dropping just the one colliding verb and leaving you to discover, method by method, which of the seven still work, `override` reroutes control entirely: the object you get back carries none of the seven verbs itself. It wraps the real double instead, so every verb still works the same way regardless of which method actually collided:

```php
$gate->expects('allows')->with('edit-post')->returns(true);
```

The one thing that's genuinely different is handing the real, `AuthorizerInterface`-shaped object to whatever you're testing — the wrapper you're holding isn't one itself, so reach for `instance()`:

```php
$service = new PolicyChecker($gate->instance());
```

`strict()`, `passthru()`, `received()`, `unused()`, and `verify()` all work exactly as they do on any other double, called on the wrapper the same way `expects()` is above.

`override: true` only changes anything when there's actually a collision to route around. Passed against a class with none, it's a no-op — `for()` returns the exact same double it always would. It also only supports a single target; combined with more than one target passed to `for()`, it's rejected.

## What Can't Be Doubled

A couple of things are rejected when you call `for()`, with a clear reason, rather than failing in a confusing way later:

- **A `final` class.** There's nothing for the double to extend — unless you opt into `Double::bypassFinals()`, covered next.
- **A class or interface that doesn't exist.** Usually a typo, or a missing `use`.

Static methods are a related, separate case: you may create a double for a class that has one, but configuring it with `expects()`/`allows()`/`received()` is rejected, since there's no instance for a static call to run through. That's covered in [Expectations](04-expectations.md#static-methods).

Most magic methods are the same kind of separate case — a double may exist for a class that declares one, but configuring most of them is rejected. `__invoke`, `__toString`, `__serialize`, `__unserialize`, and `__clone` are the exception and work like any other method. See [Magic Methods](04-expectations.md#magic-methods).

### Doubling a Final Class

`Double::bypassFinals()` lifts the `final`-class restriction for the rest of the process:

```php
use JMac\Testing\Double;

Double::bypassFinals();

$logger = Double::for(FinalLogger::class); // no longer rejected
```

It works by rewriting `final class` out of a target's source the first time PHP reads it, before compiling it — so it only helps for a class PHP hasn't loaded yet. Call it as early as possible, ideally the very first line of your PHPUnit bootstrap file, ahead of even `require __DIR__.'/vendor/autoload.php'`. A class already `use`d, instantiated, or reflected on elsewhere in the process by the time you call `bypassFinals()` is already compiled as final, and stays rejected.

It's global for the process (there's no way to know in advance which classes a later `Double::for()` call will name) and it's narrow in what it touches: only `final` immediately before `class`. A final *method* on an otherwise non-final class is left alone — the double simply inherits that method's real implementation unoverridden, same as it always has.

### Readonly Classes

Unlike `final`, a `readonly` class needs no opt-in — it doubles the same as any other class:

```php
readonly class Money
{
    public function __construct(public int $cents) {}
}

Double::for(Money::class); // works, no bypassFinals() needed
```

PHP requires every subclass of a readonly class to be readonly itself, so the generated double is marked `readonly` too whenever its target is. That costs the double nothing, since it never declares properties of its own — it only overrides methods.

## Modes

Every double has exactly one mode, chosen either when you create it or on the very next call, and it stays that way afterward. Mode answers one question: **what happens when a call doesn't match anything you've configured?** It never changes what `expects()`/`allows()` mean. Those work the same way in every mode.

### Loose (the Default)

```php
$repository = Double::for(BookRepository::class);

$repository->allows('find')->with(123)->returns($book);

$repository->find(123); // $book
$repository->find(456); // a safe default, see below
```

This is what you get from a plain `Double::for(...)`, with nothing extra to opt into. An unconfigured call never throws. It returns a sensible, type-safe value based on the method's declared return type:

| Return Type | What You Get |
|---|---|
| `void` | nothing |
| no type, nullable, or `mixed` | `null` |
| `bool` | `false` |
| `int` / `float` | `0` / `0.0` |
| `string` | `''` |
| `array` / `iterable` | `[]` |
| `self` / `static` | the double itself |
| an enum | its first case |
| a union type | the first branch that resolves cleanly, preferring `null` if it's an option |
| a non-nullable class or interface | a fresh double of that type, generated for you |

That last row is worth a moment: if `find()` is typed to return a non-nullable `Author`, an unconfigured call doesn't hand you `null` and a `TypeError` a few lines later. It hands you a real, freshly-generated `Author` double instead. This only happens once per call chain. If that generated double is then asked to produce something of its own, it stops and asks you to configure it explicitly:

```
Double `Book` was returned automatically. This only happens one
level deep from the original double. To respond to `author()`,
you'll need to configure it explicitly. For example:
`$book->allows('author')->returns($anotherDouble)`.
```

Loose mode doesn't stop you from configuring specific calls. You may freely mix "stub these calls" with "fall back to a safe default for everything else."

One exception: once a method has `expects()` registered, a call to it that doesn't match any of its configured expectations always fails, in every mode — `expects()` is a promise about that specific method, not just about the double overall, so Loose mode's safe default only ever covers methods you never mentioned at all. `allows()` doesn't raise this bar; a mismatched call to an `allows()`-only method still falls back to a safe default. See [A Call Didn't Match an `expects()`](07-failure-messages.md#a-call-didnt-match-an-expects) for what that failure looks like.

### Strict

```php
$repository = Double::for(BookRepository::class)->strict();

$repository->allows('find')->with(123)->returns($book);

$repository->find(123); // $book
$repository->find(456); // throws, nothing was configured for this
```

Any call that doesn't match a configured expectation fails immediately, by name. Reach for this when you'd rather a test fail the moment its setup is incomplete, instead of discovering it indirectly further down.

### Passthru

```php
$logger = Double::for(Logger::class)->passthru($realLogger);

$logger->info('hello');   // runs Logger::info() for real, on the double itself
$logger->error('uh oh');  // still recorded, so received('error') works
```

Unconfigured calls run for real. Anything you have configured still intercepts as usual, and every call is still recorded, whether it was intercepted or ran for real, so `received()` (see [Verification](06-verification.md)) works the same as it does in any other mode.

`passthru($realLogger)` doesn't keep `$realLogger` around and forward calls to it — it copies `$realLogger`'s state onto the double once, right then, and from that point on the double *is* the real object, running its actual code directly on itself. That's not just an implementation detail: it's what makes an unstubbed method's own internal calls to other methods on `$this` reach your stubs too, the same way overriding a method in an ordinary subclass would. If `Logger::info()` internally called `$this->format($message)`, and you'd configured `format()`, that stub would fire — even though nothing called `format()` directly from your test.

One consequence of copying state up front rather than continuing to reach into `$realLogger`: if something else mutates `$realLogger` *after* `passthru()` was called, the double won't see that later change. It's a snapshot at the moment you called `passthru()`, not a live view of `$realLogger` going forward.

`$realLogger` must be `Logger` or a subclass of it — the same rule as any real type hint. A subclass is accepted, but only `Logger`'s own methods ever run, never the subclass's overrides: passthru runs the *doubled* class's real code, not whatever class the instance you handed in actually is. Anything unrelated to `Logger` is rejected outright, since there'd be nothing for `Logger`'s own methods to run against.

One exception, easy to miss because Passthru's whole premise is "real behavior unless overridden": once a method has `expects()` registered, a call to it that doesn't match any of its configured expectations always fails — the same rule [Loose mode](#loose-the-default) follows, and for the same reason: `expects()` is a promise about that specific method, not just about the double overall, so it doesn't relax for Passthru's fallback any more than it does for Loose's. `allows()` doesn't raise this bar; a mismatched call to an `allows()`-only method still runs for real, same as a method with nothing configured for it at all. If you meant "override this one call, leave the rest real," reach for `allows()` — `expects()` is for asserting a method is called with exactly the arguments you named. See [A Call Didn't Match an `expects()`](07-failure-messages.md#a-call-didnt-match-an-expects) for what that failure looks like.

Calling `->passthru()` with no argument never runs the real constructor at all — there's nothing to copy from, so the double just keeps the uninitialized state it already has. A real method that goes on to touch a property the constructor would have set throws PHP's own clear "must not be accessed before initialization" error at that point; nothing about passthru itself needs to guess or fail early on your behalf.

This is the shape to reach for when you want to exercise one method's real logic and fake out the handful of other methods it calls along the way — testing a method's own behavior in isolation from its collaborators — even when the class's constructor needs real dependencies you have no interest in providing for this test.

The one case still rejected up front is an interface, since there's no real method body at all to run:

```
Can't auto-instantiate `Logger` to passthru. It's an interface —
so there's no constructor to invoke. You may need to pass an
existing instance instead. For example: `->passthru($existingInstance)`.
```

Passthru only applies to classes, since an interface has no real implementation to run.

If you only want one specific call to run for real, rather than the whole double, `resolves()` is the better fit. See [Expectations](04-expectations.md).
