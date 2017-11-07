# Test Double

Tired of Mockery making you think about the difference between mocks, partials, and spies?

I am, which is why I created `double()` - a simple helper method to make using Mockery easier.

This abstraction is common in other popular testing frameworks such as [RSpec](https://relishapp.com/rspec/rspec-mocks/docs/basics/test-doubles), [td.js](https://github.com/testdouble/testdouble.js), and more.

## Installation
To install the latest version of the `double()` helper, run the command:

`composer require --dev jasonmccreary/test-double`

## Show me the code…

Anytime you need to create a _test double_ simply call `double()`

By default, `double()` returns an object that will allow you to stub methods as well as verify method calls.

```php
<?php
$td = double();

$td->shouldReceive('someMethod')->andReturn(5);

$td->someMethod();       // returns 5
$td->unstubbedMethod();  // returns null, does not throw an exception

$td->anotherMethod();
$td->shouldHaveReceived('anotherMethod');
```

In Mockery, this _test double_ is equivalent to `Mockery::mock()->shouldIgnoreMissing()` or, in recent versions, `Mockery::spy()`.

You can also pass `double()` a reference to a class or interface. This will create a test object that extends the class or implements the interface. This allows the double to pass any type hints or type checking in your implementation.

```php
<?php
$td = double(Str::class);

$td->shouldReceive('length')->andReturn(5);

$td->length();           // 5
$td->substr(1, 3);       // null

$td instanceof Str;      // true

$td->shouldHaveReceived('substr')->with(1, 3);
```

Finally, `double()` accepts a second argument of `passthru`. By default, `passthru` is `false`. When set to `true`, the test object will pass any method calls through to the underlying object.

In Mockery, this is equivalent to `Mockery::mock(SomeClass::class)->shouldDeferMissing()`.

```php
<?php
class Number
{
    public function one()
    {
        return 1;
    }

    public function random()
    {
        return 5;
    }
}

$td = double(Number::class, true);

$td->shouldReceive('random')->andReturn(21);

$td->random();           // 21
$td->one();              // 1

$td instanceof Number;      // true

$td->shouldHaveReceived('one');
```

Note: `passthru` can only be used when creating a test double with a class reference as that is the only time an underlying implementation exists.

In the end, `double()` is an opinionated way to create test objects for your underlying code. If it does not meet your needs, you can always create a `Mockery::mock()` directly. However, doing so is likely a smell you're testing your implementation in a way that does not reflect real world behavior. Remember, `double()` returns an object which implements the `MockeryInterface`. So it can be treated as any other `Mockery::mock()` object.
