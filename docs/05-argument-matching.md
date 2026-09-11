# Argument Matching

A plain value passed to `with()` covers most tests: `with(123)` matches the literal `123`. What "matches" means depends on the value's type. Scalars, arrays, and `null` are compared with `===`, so the actual argument must be identical in type and value — `123` matches `123` but not `'123'`. For arrays, that includes key order: `['a' => 1, 'b' => 2]` doesn't match `['b' => 2, 'a' => 1]`. Objects are compared with `==` instead, so the actual argument must be `==`-equal: same class, same property values, but not necessarily the same instance. That default is enough for most calls, since most tests care that the right data arrived, not that it's the very same object reference.

For anything looser than that (any argument at all, an instance of a class, a string in a particular shape, or that exact instance rather than merely an equal one), reach for `Argument` and its dynamic matchers.

```php
use JMac\Testing\Matching\Argument;

$repository->allows('find')->with(Argument::any())->returns($book);
$repository->allows('save')->with(Argument::type(Book::class))->returns(true);
```

`Argument` is a small, standalone facade rather than a method on `Double` itself. A matcher constrains one argument to a call, it isn't a double, so it gets its own class.

## No Arguments at All

```php
$repository->allows('reset')->with(Argument::none())->returns(true);
```

Asserts the call took zero arguments. A bare `->with()` (nothing passed to it) already means the same thing, as a side effect of the general matching rule; `none()` exists so that intent reads clearly at the call site rather than relying on the reader already knowing that. It must be the only thing passed to `with()`.

## Any Argument

```php
$repository->allows('find')->with(Argument::any())->returns($book);
```

Matches anything, including `null`. Useful when a position needs to be filled in `with()` but you don't care what's there.

## One of Several Values

```php
$repository->allows('find')->with(Argument::any(1, 2, 3))->returns($book);
```

Give `any()` one or more values and it narrows to "matches one of these" rather than "matches anything." Each alternative may be a plain value or another matcher.

## Same Exact Instance

```php
$repository->allows('save')->with(Argument::same($book))->returns(true);
```

A plain object passed to `with()` matches an equivalent object, as described above. `Argument::same()` is for when you need this exact instance and no other, checked with `===`. It's named after PHPUnit's own `assertSame()`.

## By Type

```php
$repository->allows('save')->with(Argument::type(Book::class))->returns(true);
$repository->allows('save')->with(Argument::type('int'))->returns(true);
```

A class or interface name matches via `instanceof`. A PHP scalar type name (`'int'`, `'string'`, `'bool'`, `'array'`, and so on) matches via the corresponding `is_*()` function.

## Pattern Matching

```php
$repository->allows('find')->with(Argument::matches('/^\d+$/'))->returns($book);
```

Matches a string (or anything `Stringable`) against a regular expression. `$pattern` includes its own delimiters, the same way `preg_match()` expects. A malformed pattern is caught at setup time, not buried in a warning during the test run.

## Searching a Collection

```php
$repository->allows('saveAll')->with(Argument::contains($book))->returns(true);
$repository->allows('saveAll')->with(Argument::contains(Argument::type(Book::class)))->returns(true);
$repository->allows('saveAll')->with(Argument::contains(fn ($value, $key) => $value->isbn === '123'))->returns(true);
```

Matches an array (or anything iterable) with at least one element satisfying `$needle`: a plain value, another matcher, or a callback invoked as `($value, $key)` for each element.

## Capturing What Was Passed

```php
$captured = null;
$repository->allows('save')->with(Argument::capture($captured))->returns(true);

$repository->save($book);

$captured === $book; // true
```

Matches anything, like `any()`, and also writes the real value into `$captured` once that call is confirmed as the match. Useful when you want to run further assertions against exactly what was passed. `$captured` always holds the most recent match.

## Trailing Arguments

```php
$repository->allows('combine')->with('-', Argument::remaining())->returns('a-b-c');

$repository->combine('-', 'a');           // matches
$repository->combine('-', 'a', 'b', 'c'); // also matches
```

A trailing marker meaning "however many further arguments there are, leave them unconstrained." It must be the last argument passed to `with()`.

## Custom Logic

```php
$repository->allows('find')->with(Argument::satisfies(fn ($id) => $id > 100))->returns($book);
```

For anything the other matchers don't cover, you may pass a predicate. One trade-off to keep in mind: a failure message can only describe this as `satisfies(...)`. It has no way to show what your closure checks. If you're using `satisfies()` to express "not this," "matches this pattern," or "contains this," the matchers above produce a clearer failure message for the same idea.

## Custom Logic Across Every Argument

```php
$broadcaster->allows('broadcast')->with(Argument::all(
    fn (array $channels, string $eventName, array $payload) => $eventName === 'foo'
))->returns(true);
```

`satisfies()` above only ever sees one position. `all()` is the one matcher that sees the whole real argument list at once, spread into the predicate the same way `...$args` would — for a check that genuinely spans several arguments together, not just one. It must be the only thing passed to `with()`; a predicate that only cares about one position is better served by `satisfies()` there instead.

## Negating

```php
$repository->allows('find')->with(Argument::not(5))->returns($book);
$repository->allows('find')->with(Argument::not()->type('int'))->returns($book);
```

`not($value)` negates a plain value directly. `not()` with no argument gives you an object with its own verbs: `type()`, `same()`, `satisfies()`, `contains()`, `matches()`, `any()`. That lets a negated verb read left to right instead of nested inside out.
