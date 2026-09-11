<?php

declare(strict_types=1);

namespace JMac\Testing\Exceptions;

/**
 * Thrown at Double::for() time when the target declares a real public
 * method with the same name as one of Double's own control verbs
 * (expects, allows, strict, passthru, received, unused, verify) — a deliberate,
 * permanent trade-off (see DoubleControlMethods), not a later hardening pass.
 * `Double::for($target, override: true)` is the escape hatch (see
 * OverriddenDouble) — named directly in the message below rather than left
 * for the caller to discover on their own.
 */
class ReservedNameCollisionException extends DoubleException
{
    /**
     * @param  string[]  $collisions
     */
    public function __construct(
        public readonly string $target,
        public readonly array $collisions,
    ) {
        parent::__construct(self::lead($this->render()));
    }

    /**
     * @param  string[]  $collisions
     */
    public static function forCollisions(string $target, array $collisions): self
    {
        return new self($target, $collisions);
    }

    private function render(): string
    {
        $backtickedNames = array_map(static fn (string $name): string => "`{$name}`", $this->collisions);

        $last = array_pop($backtickedNames);
        $names = $backtickedNames === [] ? $last : implode(', ', $backtickedNames).' and '.$last;

        $message = sprintf(
            'Can\'t create a double for `%s`. It contains %s which %s with Double\'s internal methods.',
            $this->target,
            $names,
            count($this->collisions) === 1 ? 'collides' : 'collide',
        );

        // override: true only ever applies to a single target — this exception
        // also fires for an intersection double (`$target` then reads
        // "A&B"), where `Double::for()` itself rejects `override` outright
        // (see its own InvalidArgumentException), so suggesting it here would
        // be both invalid PHP syntax and a dead end.
        if (! str_contains($this->target, '&')) {
            $message .= sprintf(
                ' You may use `Double::for(%s::class, override: true)` to overcome this.',
                $this->target,
            );
        }

        return $message;
    }
}
