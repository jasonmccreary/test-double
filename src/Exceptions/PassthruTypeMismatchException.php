<?php

declare(strict_types=1);

namespace JMac\Testing\Exceptions;

/**
 * Thrown when ->passthru($realInstance) is given an instance that isn't the
 * doubled class or one of its subclasses. Passthru runs the doubled class's
 * own real method bodies on the double itself — an unrelated class has
 * nothing in common for those bodies to run against, and even a subclass's
 * own overridden behavior never enters into it (see PassthruInitializer).
 */
class PassthruTypeMismatchException extends DoubleException
{
    public function __construct(
        public readonly string $target,
        public readonly string $givenClass,
    ) {
        parent::__construct(self::lead($this->render()));
    }

    private function render(): string
    {
        return sprintf(
            '`passthru()` was given an instance of `%s`, but this is a double for `%s`. '
            .'The instance passed to `passthru()` must be `%s` or one of its subclasses.',
            $this->givenClass,
            $this->target,
            $this->target,
        );
    }
}
