<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\TestCase;

final class RedisFullCapabilityStoreIntegrationTest extends TestCase
{
    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $port = (int) $portValue;
        $this->store = new RedisFullCapabilityStore(new RespRedisCommandExecutor($host, $port), 'integration:test');
    }

    public function testScoreBudgetCorrelationAndIsolationUseRealRedis(): void
    {
        self::assertTrue($this->store->isHealthy());
        self::assertSame(2, $this->store->increment('score', 60, 2));
        self::assertSame(5, $this->store->increment('score', 60, 3));
        self::assertSame(5, $this->store->get('score')?->value);
        $budget = $this->store->incrementBudget('budget', 60, 2);
        self::assertSame(2, $budget->count);
        $storedBudget = $this->store->getBudget('budget');
        self::assertNotNull($storedBudget);
        self::assertSame($budget->count, $storedBudget->count);
        self::assertSame($budget->epochStart, $storedBudget->epochStart);
        self::assertSame(1, $this->store->addDistinct('members', 'one', 60));
        self::assertSame(1, $this->store->incrementWatchFlag('watch', 60));
    }

    public function testBoundedSnapshotAndCircuitStateRoundTrip(): void
    {
        $first = $this->store->addDistinctBoundedWithSnapshot('bounded', 'one', 60, 2);
        self::assertSame(['one'], $first->members);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded', 'two', 60, 2)->added);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded', 'three', 60, 2)->accepted);
    }
}
