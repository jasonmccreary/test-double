<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * A subclass of StatefulGreeter with its own extra property and an
 * overridden greet() — used to prove passthru($realInstance) accepts a
 * subclass instance (real PHP subtyping, the same as any type hint), copies
 * only the state StatefulGreeter itself declares, and runs StatefulGreeter's
 * own greet(), not this override. That last part is a real, documented
 * limit, not a bug: passthru only ever runs the doubled class's own method
 * bodies (see PassthruInitializer::copyState()).
 */
class ExtendedGreeter extends StatefulGreeter
{
    private string $title = 'Dr.';

    public function greet(): string
    {
        return "Greetings, {$this->title}!";
    }
}
