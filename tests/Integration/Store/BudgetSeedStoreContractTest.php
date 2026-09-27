<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class BudgetSeedStoreContractTest extends TestCase
{
    private FixedClock $clock;
    private BudgetSeedStoreInterface $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
    }

    public function testValidPreviousEpochSeedInitializesBudgetPreservingEpochStart(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seed = new BudgetStateDTO(7, $now - 3600);

        $result = $this->store->incrementBudgetWithSeed('key-a', 86400, $seed, 1);

        $this->assertSame(8, $result->count);
        $this->assertSame($seed->epochStart, $result->epochStart);

        $stored = $this->store->getBudget('key-a');
        $this->assertNotNull($stored);
        $this->assertSame(8, $stored->count);
        $this->assertSame($seed->epochStart, $stored->epochStart);
    }

    public function testExistingV2BudgetIgnoresSeed(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $existing = $this->store->incrementBudget('key-b', 86400, 3);
        $seed = new BudgetStateDTO(100, $now - 86400);

        $result = $this->store->incrementBudgetWithSeed('key-b', 86400, $seed, 1);

        $this->assertSame(4, $result->count);
        $this->assertSame($existing->epochStart, $result->epochStart);
        $this->assertSame($now, $result->epochStart);
    }

    public function testRepeatedSeedCallsDoNotReseedExistingState(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seed = new BudgetStateDTO(7, $now - 3600);

        $first = $this->store->incrementBudgetWithSeed('key-c', 86400, $seed, 1);
        $second = $this->store->incrementBudgetWithSeed('key-c', 86400, $seed, 1);

        $this->assertSame(8, $first->count);
        $this->assertSame($seed->epochStart, $first->epochStart);
        $this->assertSame(9, $second->count);
        $this->assertSame($seed->epochStart, $second->epochStart);
    }

    public function testExpiredSeedStartsANewEpoch(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seed = new BudgetStateDTO(7, $now - 7200);

        $result = $this->store->incrementBudgetWithSeed('key-d', 3600, $seed, 5);

        $this->assertSame(5, $result->count);
        $this->assertSame($now, $result->epochStart);
    }

    public function testSeedExpiringExactlyAtTheBoundaryStartsANewEpoch(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seed = new BudgetStateDTO(7, $now - 3600);

        $result = $this->store->incrementBudgetWithSeed('key-e', 3600, $seed, 3);

        $this->assertSame(3, $result->count);
        $this->assertSame($now, $result->epochStart);

        $stored = $this->store->getBudget('key-e');
        $this->assertNotNull($stored);
        $this->assertSame(3, $stored->count);
        $this->assertSame($now, $stored->epochStart);
    }

    public function testFixedEpochRemainsFixedAfterANormalIncrement(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seed = new BudgetStateDTO(7, $now - 3600);

        $this->store->incrementBudgetWithSeed('key-f', 86400, $seed, 1);

        $result = $this->store->incrementBudget('key-f', 86400, 1);

        $this->assertSame(9, $result->count);
        $this->assertSame($seed->epochStart, $result->epochStart);
    }
}
