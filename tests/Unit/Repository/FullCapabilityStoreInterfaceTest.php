<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;

final class FullCapabilityStoreInterfaceTest extends TestCase
{
    public function testAggregateInterfaceExistsAndInheritsTheLockedCapabilities(): void
    {
        $aggregate = 'Maatify\\RateLimiter\\Repository\\FullCapabilityStoreInterface';
        $parents = [
            'Maatify\\RateLimiter\\Repository\\BudgetSeedStoreInterface',
            'Maatify\\RateLimiter\\Repository\\BoundedCorrelationSnapshotRotationStoreInterface',
            'Maatify\\RateLimiter\\Repository\\CircuitBreakerProbeStoreInterface',
            'Maatify\\RateLimiter\\Repository\\HardBlockCycleStoreInterface',
        ];

        self::assertTrue(interface_exists($aggregate));
        $reflection = new \ReflectionClass($aggregate);
        $inheritedInterfaces = $reflection->getInterfaceNames();

        foreach ($parents as $parent) {
            self::assertContains($parent, $inheritedInterfaces);
        }

        foreach ($reflection->getMethods() as $method) {
            self::assertNotSame($aggregate, $method->getDeclaringClass()->getName());
        }
    }

    public function testAggregateInterfaceAddsNoMethodsBeyondItsInheritedContracts(): void
    {
        $reflection = new \ReflectionClass(
            'Maatify\\RateLimiter\\Repository\\FullCapabilityStoreInterface',
        );

        $declaredMethods = array_values(array_filter(
            $reflection->getMethods(),
            static fn(\ReflectionMethod $method): bool
                => $method->getDeclaringClass()->getName() === $reflection->getName(),
        ));

        self::assertSame([], $declaredMethods);
    }
}
