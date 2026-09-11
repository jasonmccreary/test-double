# Test Suite Integration

Nothing in this library requires PHPUnit. But if it's installed in your project, three things improve automatically — and since Pest tests run on real `PHPUnit\Framework\TestCase` instances under the hood, all three apply the same way whether you're on PHPUnit or Pest.

## Failures Read as Failures

PHPUnit only reports a thrown exception as a **failure** (attributed cleanly to the assertion that didn't hold) if it extends PHPUnit's own `AssertionFailedError`. Anything else is reported as an **error** instead. An unmatched call or an unmet expectation is very much the former, so when PHPUnit is present, this library's exceptions are automatically the failure-flavored version. There's nothing to configure. It's detected the moment your tests run, and it works the same way with both PHPUnit 11 and 12.

Setup mistakes remain **errors**, which is correct: a `final` class you tried to double, or a reserved method name collision, tell you the test can't run as written, not that an assertion about your code's behavior failed.

## Passing Checks Count as Assertions

PHPUnit flags a test "risky" when it runs to completion without ever touching its assertion counter, usually a sign the test forgot to check anything. A test whose only check is `$double->received(...)`, `$double->unused()`, or a satisfied `expects()`/`allows()` verified via `verify()` (or the `VerifiesDoubles` trait below) is a real, meaningful check, but without this integration PHPUnit would have no way to know that: nothing in this library ever touched PHPUnit's own assertion API.

When PHPUnit is present, a passing verification registers a genuine PHPUnit assertion behind the scenes, so tests like these are never flagged as risky. Nothing to configure. This is on by default, and a failing check throws exactly as before.

## Automatic Verification

`JMac\Testing\Integrations\PHPUnit\VerifiesDoubles` is a trait you mix into your test suite to stop calling `->verify()` yourself. Unlike the two checks above, this one needs a deliberate step to enable — it hooks in via PHPUnit's `#[Before]`/`#[After]` attributes, so it only works on a suite built on `PHPUnit\Framework\TestCase`, which covers both PHPUnit and Pest, just wired in differently for each. See [Framework Integration](#framework-integration) below for the setup.

Without it (or a manual `verify()`), `expects()`'s call-count promise is never checked — a call that's expected but never made passes silently, same as `allows()`.

Once enabled, every double created during a test (and every `received()` assertion made on one) is checked automatically once that test finishes. `$double->verify()` still works everywhere, including here — adding the trait doesn't change what `verify()` does, it just gives you a way to stop calling it yourself. If you're on a different test runner, or don't want the trait, calling `verify()` explicitly, as shown in [Verification](06-verification.md), is the only thing you need.

## Driving Auto-Verification From a Custom Runner

`VerifiesDoubles` is built entirely on public API, so a runner other than PHPUnit/Pest can get the same "enable before, verify after" behavior without the trait:

```php
Double::enableAutoVerify(); // before the test runs

// ...the test runs, creating doubles and received() assertions...

$checkCount = Double::verifyAll(); // after the test finishes
```

`enableAutoVerify()` resets and starts collecting every double created (and every `received()` assertion made) from that point on. `verifyAll()` checks everything collected since the matching `enableAutoVerify()`, then turns auto-verification back off.

Outside PHPUnit, nothing tells a runner one of these checks actually happened — [Passing Checks Count as Assertions](#passing-checks-count-as-assertions) above is PHPUnit-only. `verifyAll()`'s return value is the framework-agnostic version of that same signal: how many doubles and `received()` assertions it just checked. A runner that flags tests with zero assertions as risky can feed this count into whatever it uses for that, the same way PHPUnit feeds off its own assertion counter.

That pairing assumes one test runs straight through from enable to verify. A runner that interleaves tests in one process — fibers or coroutines sharing a process, for instance — needs to pause one test's in-flight state during a context switch so a sibling test resuming next doesn't sweep its doubles into the wrong `verifyAll()`. `Double::pauseAutoVerify()` lifts the live state out into an opaque `AutoVerifySnapshot` and resets the live state to off/empty; `Double::resumeAutoVerify()` installs a previously paused snapshot back as the live state:

```php
$parked[$fiberId] = Double::pauseAutoVerify(); // test suspends

// ...a sibling test runs in the meantime...

Double::resumeAutoVerify($parked[$fiberId]); // test resumes
```

`resumeAutoVerify()` overwrites the live state rather than merging into it, so always pair it with a `pauseAutoVerify()` of whatever's currently live if that state still needs checking.

## Framework Integration

Wiring the `VerifiesDoubles` trait in looks a little different depending on your framework:

### PHPUnit Integration

Add the trait to your base test case:

```php
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use VerifiesDoubles;
}
```

### Pest Integration

Pest doesn't use a base test case in the traditional sense, so mix the trait in with `uses()` instead, typically in `tests/Pest.php`:

```php
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

uses(VerifiesDoubles::class)->in('Feature', 'Unit');
```

Scope the `in()` call to whichever directories your doubles live in — the whole `tests/` directory works too if you want it everywhere.
