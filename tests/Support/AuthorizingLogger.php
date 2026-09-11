<?php

declare(strict_types=1);

namespace JMac\Testing\Tests\Support;

/**
 * A concrete class, not an interface — AuthorizerInterface can't stand in
 * for OverriddenDouble::passthru() tests since inline/instance passthru
 * both need a real class body to fall back to. Combines the same real
 * reserved-name collision AuthorizerInterface has (`allows()`) with an
 * unrelated real method (`log()`) to delegate an unmatched call to.
 */
class AuthorizingLogger
{
    public function allows(string $ability): bool
    {
        return true;
    }

    public function log(string $message): bool
    {
        return true;
    }
}
