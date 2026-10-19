<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;

/** The settlement contract on the production store, against a real Redis. */
#[Group('settle')]
final class AdmissionSettlementContractRedisTest extends AdmissionSettlementContractTestCase
{
    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface
    {
        return new RedisFullCapabilityStore($this->executor, 'integration:admission-settle');
    }
}
