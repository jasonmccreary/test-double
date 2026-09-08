---
title: What about "unnamed mocks"?
description: Why Double doesn't offer an equivalent to Mockery::mock() and what to reach for instead.
published: 2026-09-08
---

# What about "unnamed mocks"?

```php
$mock = Mockery::mock();
$mock->shouldReceive('foo')->andReturn('bar');
```

Mockery lets you create a mock without naming a class or interface. They call it an "unnamed mock." Double doesn't have such a feature, and that's deliberate.

## No type
Since this mock doesn't have a target, it also doesn't have a type. This means it doesn't pass a type-hint. Its usefulness shrinks to untyped parameters, dynamic properties, and code that never checks type. Historically, that was most PHP code. Nowadays that's less and less code.

Double is a modern PHP testing library, designed for modern PHP codebases.

## It's not a mock, it's a stub
If you strip away the `mock` name, you're really creating a `stub`. That is, you're not verifying behavior so much as providing canned responses during the tests. That's the very [definition of a stub](https://martinfowler.com/bliki/TestDouble.html).

Double focuses on _mocks_ and _spies_. The other types of test doubles may be written directly.

## Alternative
This brings us to a more appropriate alternative. If you simply need a stand-in, with no behavior, you may use a plain PHP array or `stdClass`.

```php
$dummy = new stdClass();
```

It's far less code. There's no special object to create and no expectations to define.

If you do need limited behavior, you can add the necessary methods to provide the answers in your test.

Consider a test for the following untyped, legacy code.

```php
function applyDiscount($order)
{
    return $order->getTotal() * 0.9;
}
```

To test this, you may create an anonymous class and define the necessary method for your specific test case:

```php
// arrange
$order = new class {
    public function getTotal(): float
    {
        return 200.0;
    }
};

// act
$discounted = applyDiscount($order);
```

In this case, it's arguably the same amount of code. But if you were testing this repeatedly, you could actually create a _stub_ class to use for testing. Of course, if the code actually has a class for order, you could also use the real thing.
