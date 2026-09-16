<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Exception\RateLimiterExceptionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class RateLimiterExceptionTest extends TestCase
{
    public function testInterfaceExtendsThrowable(): void
    {
        $reflection = new \ReflectionClass(RateLimiterExceptionInterface::class);
        $this->assertTrue($reflection->implementsInterface(Throwable::class));
    }

    public function testExceptionImplementsInterface(): void
    {
        $exception = new RateLimiterException('message');
        $this->assertInstanceOf(RateLimiterExceptionInterface::class, $exception);
    }

    public function testExceptionIsInvalidArgumentMaatifyException(): void
    {
        $exception = new RateLimiterException('message');
        $this->assertInstanceOf(InvalidArgumentMaatifyException::class, $exception);
    }

    public function testExceptionRemainsRuntimeExceptionThroughHierarchy(): void
    {
        $exception = new RateLimiterException('message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testExceptionMaintainsBackwardCompatibleConstructor(): void
    {
        $previous = new RuntimeException('previous message');
        $exception = new RateLimiterException('test message', 123, $previous);

        $this->assertSame('test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
