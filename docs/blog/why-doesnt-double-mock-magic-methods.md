---
title: Why doesn't Double mock magic methods?
description: __call() has no fixed shape to check a stub against. Two real examples show what to double instead — and one case where the answer isn't Double at all.
published: 2026-09-10
---

# Why doesn't Double mock magic methods?

```php
$connection->expects('keys')->returns([...]);
// Can't configure `keys` on a double for `PhpRedisClusterConnection`
// because it doesn't declare this method.
```

`PhpRedisClusterConnection` really does answer to `keys()`, in the sense that you can call it and it works. It just isn't a *declared* method — like the rest of Laravel's Redis connection classes, it forwards unrecognized calls through `__call()` to whatever real client it wraps. Reflection sees `__call`, `command`, `client` — the dispatch machinery — and nothing named `keys` at all.

Double requires `expects()`/`allows()` to target a method the target class actually declares. `__get`, `__set`, `__call`, and friends are rejected outright, for all of them, no exceptions. That's a deliberate line, not an oversight, and it's worth explaining why — because the two real cases that ran into it, converting Laravel's own test suite from Mockery, had two completely different right answers, and neither of them was "let Double be looser."

## There's no shape to check a stub against

Every other kind of expectation Double supports rests on knowing the method's real signature. That's what makes arity checking possible, what makes `Argument::remaining()` meaningful, what makes "did you mean `sendEmail`?" possible when you typo a method name. `__call($name, $arguments)` throws all of that away — from reflection's point of view, *every* method call arrives as the same two arguments to the same one method. There's no fixed shape to check `expects('keys')` against, because as far as PHP's type system is concerned, `keys` isn't a real thing on this class.

Loosening this — accepting any method name and trusting the caller to know it's real — would mean giving up the exact thing that makes a double catch a typo or a signature change instead of silently passing. That's a worse trade for the overwhelming majority of doubles just to accommodate the handful of classes built entirely on `__call`.

So instead: what do you actually do when the class you need to double is one of those?

## Case 1 — double the real object, not the proxy

Laravel's Redis connections forward almost everything through `__call()`:

```php
// Connection::__call()
public function __call($method, $parameters)
{
    return $this->command($method, $parameters);
}
```

`command()` ultimately runs `$method` on `$this->client` — the real phpredis (or Predis) client the connection wraps, exposed via `Connection::client()`. Converting Laravel's own `QueueRedisQueueTest` left one call stubbed against a placeholder that couldn't possibly declare the forwarded method:

```php
// Before — Double::for(\stdClass::class) doesn't declare getOption()
$redis = Double::for(Factory::class);
$connection = Double::for(PhpRedisClusterConnection::class);
$client = Double::for(\stdClass::class);

$redis->expects('connection')->returns($connection);
$connection->expects('client')->returns($client);
$client->expects('getOption')->with(\Redis::OPT_SCAN)->returns(\Redis::SCAN_PREFIX);
$connection->expects('scan')->with(null, ['match' => 'queues:*', 'count' => 1000])
    ->returns([null, ['test_queues:{default}']]);
```

`\stdClass` doesn't declare `getOption()` any more than `PhpRedisClusterConnection` declares `keys()` — it just happened to be what the converter reached for as a stand-in for "whatever `client()` returns." The fix is a one-word change: double what `client()` *actually* returns in production, the real `\Redis` extension class, which really does declare `getOption()`:

```php
// After — \Redis::class is a real extension class with a real getOption()
$redis = Double::for(Factory::class);
$connection = Double::for(PhpRedisClusterConnection::class);
$client = Double::for(\Redis::class);

$redis->expects('connection')->returns($connection);
$connection->expects('client')->returns($client);
$client->expects('getOption')->with(\Redis::OPT_SCAN)->returns(\Redis::SCAN_PREFIX);
$connection->expects('scan')->with(null, ['match' => 'queues:*', 'count' => 1000])
    ->returns([null, ['test_queues:{default}']]);
```

Same shape applies directly to the `keys()` case from the top of this post — double `\Redis::class`, configure `keys()` on it (a real, declared method), and let the connection's own `client()` hand it back:

```php
$client = Double::for(\Redis::class);
$client->expects('keys')->returns([...]);

$connection = Double::for(PhpRedisClusterConnection::class);
$connection->allows('client')->returns($client);
```

This isn't a workaround you settle for because Double can't do better. It's a more honest test. The old version was really asserting "something that can answer `getOption()`," which `stdClass` would happily fake without ever proving the real client shape matches. The fixed version exercises the real forwarding path (`__call` → `command()` → `$this->client->{$method}()`) against a type that actually has to match `\Redis`'s real signature. If someone later changes how `command()` dispatches, this test can actually catch it. The old one couldn't have.

The same move works one layer up the stack, too. `Illuminate\Http\Client\Factory::get()` doesn't exist — `Factory::__call()` builds a `PendingRequest` and calls the method there — so doubling `PendingRequest::class` instead of `Factory::class` gets you a real, declared `get()` to configure. Different framework, same shape: find the concrete class `__call` actually hands off to, and double that.

## Case 2 — there's already a better way

The Redis fix generalizes because `__call` is forwarding to *something concrete*. AWS SDK clients don't have that something:

```php
$client = Double::for(SesClient::class);
$client->expects('sendRawEmail')->with(Argument::satisfies(function ($arg) {
    return $arg['Source'] === 'myself@example.com'
        && $arg['Destinations'] === ['me@example.com', 'you@example.com'];
}))->returns($sesResult);
// Can't configure `sendRawEmail` on a double for `Aws\Ses\SesClient`
// because it doesn't declare this method.
```

There's no `SesOperations` class sitting behind `SesClient::__call()` the way `PendingRequest` sits behind `Factory::__call()`. Every AWS SDK client's operations — `sendRawEmail` included — are assembled from a runtime service model the moment they're invoked. There is nothing concrete to retarget to.

That sounds like a dead end, but it isn't — it's a sign the question was aimed at the wrong layer. AWS SDK for PHP ships its own answer to "how do I test code that calls this client," and it doesn't involve mocking the client object at all: `Aws\MockHandler`, a fake HTTP transport wired into a *real* client.

```php
// After — a real SesClient, faked only at the HTTP transport layer
$mock = new MockHandler();
$mock->append(new Result([
    'MessageId' => 'ses-message-id',
    '@metadata' => ['statusCode' => 200],
]));

$client = new SesClient([
    'region' => 'us-east-1',
    'version' => 'latest',
    'credentials' => ['key' => 'foo', 'secret' => 'bar'],
    'handler' => $mock,
]);

(new SesTransport($client))->send($message);

$sent = $mock->getLastCommand()->toArray();
$this->assertSame('myself@example.com', $sent['Source']);
$this->assertSame(['me@example.com', 'you@example.com'], $sent['Destinations']);
```

`MockHandler` sits underneath the client and hands back canned `Result`/exception objects instead of making a request. The client itself is real — command building, parameter validation, and response parsing all run for real, not stubbed away. It's a stronger test than a mocked `sendRawEmail()` call ever was, because it's exercising code the mock was skipping entirely — and it's what the Redis fix above was already doing in spirit: prefer the real, narrow seam over a wider one that only looks like the real class.

This is the one case where the right move genuinely isn't a Double API at all. When a third-party library's whole surface is `__call`-generated with nothing concrete underneath, check whether the library ships its own test-doubling mechanism first — a lot of SDKs that lean on runtime dispatch also ship a fake transport for exactly this reason, since they ran into the same problem building their own test suite.

## The general rule

`Double::for()` won't stub a method it can't verify exists. When `__call` is in the way, there are two questions worth asking, in order:

1. Is there a concrete class `__call` is actually forwarding to? Double that instead.
2. If not, does the library already have its own answer for testing code that uses it?

Only if both come up empty are you actually stuck — and at that point, the fix is usually to introduce a seam your own code owns (an adapter, a thin interface) and double that, rather than trying to intercept a dispatch mechanism that was never designed to be interceptable in the first place.
