<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * A plain, non-final, real class — the general-purpose stand-in for most of
 * PassthruModeTest, as opposed to ConcreteLogger (whose constructor always
 * throws, used to prove passthru() never runs it), StatefulGreeter (used
 * where a test needs to prove real *state* carried over, not just real
 * behavior), or FinalLogger (which can't be doubled at all).
 */
class InstantiableLogger
{
    public function log(string $message): bool
    {
        return true;
    }
}
