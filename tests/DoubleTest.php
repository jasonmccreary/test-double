<?php

namespace TestDouble\Tests;

use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Knowning\Adder;
use Knowning\Math;

class DoubleTest extends TestCase
{
    public function testDoubleReturnsBaseSpyForPassedNoArguments()
    {
        $actual = double();

        $this->assertInstanceOf(MockInterface::class, $actual);
        $this->assertFalse(get_parent_class($actual));

        $this->assertNull($actual->unstubbedMethod(1, 2, 3));
        $actual->shouldHaveReceived('unstubbedMethod')->with(1, 2, 3)->once();
    }

    public function testDoubleThrowsInvalidArgumentExceptionForPassthruIsSet()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must provide a class reference to use passthru.');

        double(null, true);
    }

    public function testDoubleReturnsInstanceSpyForPassedClassReference()
    {
        $actual = double(TestDouble::class);

        $this->assertInstanceOf(MockInterface::class, $actual);
        $this->assertInstanceOf(TestDouble::class, $actual);

        $this->assertNull($actual->unstubbedMethod(1, 2, 3));
        $actual->shouldHaveReceived('unstubbedMethod')->with(1, 2, 3)->once();
    }

    public function testDoubleReturnsPartialMockForPassedClassReferenceAndPassthru()
    {
        $actual = double(TestDouble::class, true);

        $this->assertInstanceOf(MockInterface::class, $actual);
        $this->assertInstanceOf(TestDouble::class, $actual);

        $this->assertTrue($actual->realMethod(1, 2, 3));

        $this->expectException(\Mockery\Exception\BadMethodCallException::class);
        $actual->unstubbedMethod(1, 2, 3);
    }

    public function testDoubleReturnsInterfaceSpyForPassedInterfaceReference()
    {
        $actual = double(TestDoubleInterface::class);

        $this->assertInstanceOf(MockInterface::class, $actual);
        $this->assertInstanceOf(TestDoubleInterface::class, $actual);
        $this->assertFalse(get_parent_class($actual));

        $this->assertNull($actual->unstubbedMethod(1, 2, 3));
        $actual->shouldHaveReceived('unstubbedMethod')->with(1, 2, 3)->once();
    }

    public function testDoubleThrowsInvalidArgumentExceptionForInterfaceReferenceAndPassthru()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must provide a class reference to use passthru.');

        double(TestDoubleInterface::class, true);
    }

    public function testDoubleThrowsInvalidArgumentExceptionForInvalidReference()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must mock an existing class or interface. Could not find: stuff');

        double('stuff');
    }
}
