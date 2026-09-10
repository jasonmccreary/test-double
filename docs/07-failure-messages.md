# Failure Messages

A failing test is a message to whoever's looking at it next. This library aims for every one of its messages to name the double, name the call, and point at what to do about it.

Here's what you'll actually see when things go wrong.

## An Expectation Wasn't Met

The most common cause isn't a call that never happened. It's a call that happened with a slightly different value than expected: a typo, a stale variable, a case-sensitivity slip. `verify()` doesn't just report that an expectation went unmet. If that method was called with something else, it shows you exactly what:

```php
$repository->expects('find')->with('baz');

$repository->find('Baz'); // note the capital B

$repository->verify();
```

```
Double `foo` expected `find('baz')` to be called exactly 1 time, but it was never called.

The following similar call was made to `find`:
  value:
    - 'baz'
    + 'Baz'
```

That block only appears when there's exactly one call to correlate against — with two or more, there's no fact-based way to say which one this expectation was "supposed" to match, so the message doesn't guess. "Similar," rather than the plainer "the following calls...were made," is warranted here specifically: it's a checked fact that this one call has the right shape (same method, same argument count), not a guess.

`value` is `find()`'s real parameter name, not a generic "argument 1" — reflected straight off the interface being doubled. Every argument gets its own labeled line this way, whether it matched or not, so a multi-argument call reads as one block instead of scattered fragments:

```
Double `foo` expected `save('baz', 5, true)` to be called exactly 1 time, but it was never called.

The following similar call was made to `save`:
  value: 'baz'
  count:
    - 5
    + 6
  active: true
```

`value` and `active` matched, so they're shown plainly for context; `count` didn't, so it gets a diff instead. As many arguments can differ as actually did — nothing gets collapsed away.

When a differing value is a long string, its diff elides whatever's shared instead of dumping both values in full — collapsing to `…`, keeping just the part that's actually different:

```
  query:
    - '…m dolor sit baz amet, con…'
    + '…m dolor sit Baz amet, con…'
```

A multi-line string (a JSON body, a rendered template, a SQL statement) diffs line by line instead, the same way a `git diff` would — only the changed line, plus a line of context on each side, survives:

```
  body:
      ...
          "id": 1,
    -     "name": "baz",
    +     "name": "Baz",
          "active": true
      ...
```

## A Call Wasn't Configured

In [Strict mode](03-creating-doubles.md#strict), a call that doesn't match a configured expectation fails the moment it happens:

```
Double `foo` received an unexpected call to `bar(1, 2)`. Strict mode
requires every call to be configured. For example:
`$foo->allows('bar')->returns(...)`.
```

That example is a starting point, not something to paste verbatim. `$foo` is only a best guess at your variable name (derived from the double's label), and whether you actually want `allows()` or `expects()` here is your call to make, not the library's.

If `bar` was already called successfully elsewhere in the test, you'll see that instead of the guess: a fact pulled straight from the call log, not a suggestion:

```
Double `foo` received an unexpected call to `bar(1, 2)`. Strict mode
requires every call to be configured.

The following calls to `bar` were made during this test: `bar(1)`
```

That example shows a different argument count (`bar(1)` vs. `bar(1, 2)`), so there's no argument-by-argument pairing to make — just the fact that a call happened. When the prior call has the same shape, though, it gets the same diff treatment as an unmet expectation, for the same reason: pairing this call against that one specific prior call is a fact, not a guess, once it's the only one on record:

```
Double `foo` received an unexpected call to `bar('Baz')`. Strict mode
requires every call to be configured.

The following similar call was made to `bar`:
  value:
    - 'baz'
    + 'Baz'
```

This same diff also appears for a `received($method)->with(...)` assertion (see [Verification](06-verification.md)) that fails on argument mismatch — the same fact, checked against the past instead of the future.

### Method Name Suggestions

A typo'd method name is caught the moment you configure it:

```php
$repository->expects('sav'); // meant "save"
```

```
Can't configure `sav` on a double for `BookRepository`. That method
does not exist. Did you mean `save`?
```

The suggestion only appears when something is genuinely close. If nothing is, the message simply doesn't guess.

## A Call Didn't Match an `expects()`

`expects()` raises the bar for its own method, regardless of the double's mode. In [Loose mode](03-creating-doubles.md#loose-the-default) — the default — a call to a method you never mentioned falls back to a safe default. But once you've written `expects()` for a method, a call to it that doesn't match any of its configured expectations fails immediately, the same way it would in Strict mode:

```php
$repository = Double::for(BookRepository::class);
$repository->expects('find')->with(123)->returns($book);

$repository->find(456); // fails immediately, even though the double is Loose
```

```
Double `foo` received a call to `find(456)` that doesn't match any
of its configured expectations. `expects()` requires every call to
match one exactly.

The following similar call was made to `find`:
  id:
    - 123
    + 456
```

This is deliberate: without it, a misconfigured `expects()` tends to surface as a confusing failure somewhere downstream instead — a `null` where you expected a real value, an assertion failing on the symptom instead of the cause. Only `expects()` raises this bar. A mismatched call to an `allows()`-only method still falls back to a safe default, the same as a method with nothing configured for it at all — `allows()` is the verb for "handle this case if it happens," not a promise about every call.

The same rule applies in [Passthru mode](03-creating-doubles.md#passthru), and there it's worth a second look, since Passthru's whole premise is "real behavior unless overridden" — you might expect an unmatched call to simply run for real the way an unmatched call to an `allows()`-only method does. It doesn't. The message calls this out directly:

```php
$logger = Double::for(Logger::class)->passthru($realLogger);
$logger->expects('log')->with('hello')->returns(true);

$logger->log('goodbye'); // fails, does not run the real log()
```

```
Double `foo` received a call to `log('goodbye')` that doesn't match
any of its configured expectations. `expects()` requires every call
to match one exactly.

The following similar call was made to `log`:
  message:
    - 'hello'
    + 'goodbye'

Note: this double is in passthru mode, but uses `expects()` — where
every call must have a configured expectation. Use `allows()` instead
if you want unmatched calls passed through to the real object.
```

With two or more expectations registered for the method, there's no single one to diff against, so the message lists every configured pattern instead of guessing:

```
Double `foo` received a call to `find(456)` that doesn't match any
of its configured expectations. `expects()` requires every call to
match one exactly.

This double has 2 expectations configured for `find`, but none
match this call: `find(123)`, `find(789)`
```

## A Call Happened Too Many Times

If a call matches an expectation but would push it past its configured maximum, that call fails immediately:

```
Double `foo` received 4 calls to `bar(1)`, but your expectation
only allowed 3 calls.
```

## A Call Happened Out of Order

For expectations marked [`ordered()`](04-expectations.md#keeping-calls-in-order), a call that arrives too early fails immediately, naming both methods involved:

```
Double `Connection` received `open()` out of order. Using `ordered`,
this was expected to be called before `close()` was called.
```

## Setup Mistakes

A handful of failures are about the test's setup rather than the double misbehaving mid-test, and these are caught the moment you make the mistake:

- Doubling a class that doesn't exist, or that's `final` (see [What Can't Be Doubled](03-creating-doubles.md#what-cant-be-doubled)).
- A method name that collides with the library's own control verbs (see [Reserved Method Names](03-creating-doubles.md#reserved-method-names)).
- Setting a double's mode twice.
- Configuring a static method with `expects()`/`allows()`/`received()` (see [Static Methods](04-expectations.md#static-methods)).
- Calling `->passthru()` with no argument when there's nothing to auto-instantiate.

## Fabricated Doubles

[Loose mode](03-creating-doubles.md#loose-the-default) sometimes hands you back a freshly-generated double rather than a plain value. Any failure message involving one of those says so plainly:

```
Note: this double was returned automatically. You will need to
configure it explicitly if you want it to behave differently.
```

And if a generated double is asked to fabricate something of its own, that's where a line is drawn:

```
Double `Book` was returned automatically. This only happens one
level deep from the original double. To respond to `author()`,
you'll need to configure it explicitly. For example:
`$book->allows('author')->returns($anotherDouble)`.
```

One generated double for free, and then a clear stop with a fix you may paste directly, rather than a silently growing chain of generated objects.
