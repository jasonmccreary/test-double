<?php

if (function_exists('double')) {
    throw new RuntimeException('The double function already exists. You will need to resolve the conflict or remove the jasonmccreary/test-double package.');
} else {
    /**
     * @param string|null $reference string reference to class or interface
     * @param bool $passthru whether to pass unstubbed method calls thru to the underlying class
     *
     * @return \Mockery\Mock
     */
    function double($reference = null, $passthru = false)
    {
        if (is_null($reference)) {
            if ($passthru) {
                throw new InvalidArgumentException('You must provide a class reference to use passthru.');
            }

            return Mockery::mock()->shouldIgnoreMissing();
        }

        if (is_string($reference)) {
            if (!(class_exists($reference) || interface_exists($reference))) {
                throw new InvalidArgumentException('You must mock an existing class or interface. Could not find: ' . $reference);
            }

            if ($passthru && interface_exists($reference)) {
                throw new InvalidArgumentException('You must provide a class reference to use passthru.');
            }
        }

        if ($passthru) {
            return Mockery::mock($reference)->shouldDeferMissing();
        } else {
            return Mockery::mock($reference)->shouldIgnoreMissing();
        }
    }
}
