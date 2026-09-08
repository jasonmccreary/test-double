---
title: Why is it called "Double"?
description: "Double" borrows its name from "test double," the generic term for any stand-in used in place of a real dependency in a test.
published: 2026-08-05
---

# Why is it called "Double"?
When testing it's common to say "mock". But _mock_ is actually a specific type of test object. _Spy_ is another. As well as, _fake_, _stub_, and _dummy_. The generic term for a test object is _test double_. Or, more simply, _double_. That's where this library gets its name. Underneath, Double uses _mocks_, _spies_, and _stubs_. But instead of forcing you to distinguish between these as some other libraries, it derives it based on your expectations.

So, a bit pedantic, but technically accurate. For a fuller history of these names and good definitions of each, read Martin Fowler's [TestDouble](https://martinfowler.com/bliki/TestDouble.html).
