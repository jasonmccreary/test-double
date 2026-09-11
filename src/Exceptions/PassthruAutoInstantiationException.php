<?php

declare(strict_types=1);

namespace JMac\Testing\Exceptions;

/**
 * Thrown when ->passthru() is called with no argument and the target is an
 * interface — the only case that's still rejected outright, since an
 * interface has no real method bodies at all for ClassGenerator to have
 * generated a "__td_real_*" sibling for (see
 * ClassGenerator::buildRealMethod()). A class, even one whose constructor
 * needs arguments this has no way to supply, is never rejected here — see
 * PassthruInitializer::assertConstructible().
 */
class PassthruAutoInstantiationException extends DoubleException
{
    public function __construct(
        public readonly string $target,
        public readonly string $reason,
    ) {
        parent::__construct(self::lead($this->render()));
    }

    public static function isInterface(string $target): self
    {
        return new self($target, "It's an interface — so there's no constructor to invoke.");
    }

    private function render(): string
    {
        return sprintf(
            'Can\'t auto-instantiate `%s` to passthru. %s You may need to pass an existing instance '
            .'instead. For example: `->passthru($existingInstance)`.',
            $this->target,
            $this->reason,
        );
    }
}
