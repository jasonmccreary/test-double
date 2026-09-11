<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * A real constructor that sets a property greet() actually reads — used to
 * prove passthru()'s copyState() (an existing instance) and
 * assertConstructible() (no instance, auto-instantiated) both leave the
 * double's own state matching a real instance, not just its behavior.
 */
class StatefulGreeter
{
    private string $name;

    public function __construct(string $name = 'World')
    {
        $this->name = $name;
    }

    public function greet(): string
    {
        return "Hello, {$this->name}!";
    }
}
