<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

/** The admission store contract on the production store, against a real Redis. */
final class AdmissionStoreContractRedisTest extends AdmissionStoreContractTestCase
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
        return new RedisFullCapabilityStore($this->executor, 'integration:admission');
    }

    public function testCounterAdmissionWithoutPermitOrMemberLeavesDbSizeAndExistsUnchanged(): void
    {
        $store = $this->newStore();
        $key = 'integration:admission:c:' . hash('sha256', S::CLASS_NAME . "\0c:no-member");
        self::assertSame(0, $this->executor->execute(['DBSIZE']));
        self::assertSame(0, $this->executor->execute(['EXISTS', $key]));
        try {
            $store->reserveAdmission(S::request(S::counter('src', 'c:no-member', 5)), self::NOW);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $e) {
            self::assertSame('A counter admission scope requires a member.', $e->getMessage());
        }
        self::assertSame(0, $this->executor->execute(['DBSIZE']));
        self::assertSame(0, $this->executor->execute(['EXISTS', $key]));
    }
}
