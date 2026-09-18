<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Contract\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BudgetSeedStoreCapabilityTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function interfaceHierarchyProvider(): array
    {
        return [
            'BudgetSeedStore extends RateLimitStore' => [
                BudgetSeedStoreInterface::class,
                RateLimitStoreInterface::class,
            ],
        ];
    }

    /**
     * @param class-string $childInterface
     * @param class-string $parentInterface
     */
    #[DataProvider('interfaceHierarchyProvider')]
    public function testCapabilityInterfaceIsAdditiveOverRateLimitStoreInterface(string $childInterface, string $parentInterface): void
    {
        $reflection = new ReflectionClass($childInterface);

        $this->assertTrue(
            $reflection->isSubclassOf($parentInterface),
            'BudgetSeedStoreInterface must extend RateLimitStoreInterface.'
        );
    }

    public function testBudgetCapableTestStoreSatisfiesBothContracts(): void
    {
        $store = new InMemoryRateLimitStore(new FixedClock());

        $this->assertInstanceOf(RateLimitStoreInterface::class, $store);
        $this->assertInstanceOf(BudgetSeedStoreInterface::class, $store);
    }

    public function testThrowingStoreRemainsBaseContractOnly(): void
    {
        $reflection = new ReflectionClass(ThrowingRateLimitStore::class);

        $this->assertTrue(
            $reflection->implementsInterface(RateLimitStoreInterface::class),
            'ThrowingRateLimitStore must remain a valid RateLimitStoreInterface.'
        );
        $this->assertFalse(
            $reflection->implementsInterface(BudgetSeedStoreInterface::class),
            'ThrowingRateLimitStore must NOT implement the budget-seeding capability.'
        );
    }
}
