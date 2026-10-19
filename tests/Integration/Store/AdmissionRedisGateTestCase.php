<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRedisDump;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\TestCase;

/** Shared fixture of the real-Redis store gates (NM-6, NM-7, ROT, FAIL, PERMIT). */
abstract class AdmissionRedisGateTestCase extends TestCase
{
    protected const NAMESPACE = 'integration:admission-gates';

    protected string $host;

    protected int $port;

    protected RespRedisCommandExecutor $executor;

    protected RedisFullCapabilityStore $store;

    protected RedisAdmissionOperations $keys;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->executor = new RespRedisCommandExecutor($this->host, $this->port);
        $this->resetRedis();
    }

    protected function resetRedis(): void
    {
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
        $this->keys = new RedisAdmissionOperations($this->executor, self::NAMESPACE);
    }

    protected function key(AdmissionScopeKindEnum $kind, string $logical): string
    {
        return $this->keys->scopeKey(S::CLASS_NAME, $kind, $logical);
    }

    /** @return array<string, array{type: string, value: mixed, hasTtl: bool}> */
    protected function dump(): array
    {
        return AdmissionRedisDump::take($this->executor);
    }

    /** @return array<string, int> */
    protected function pttl(): array
    {
        return AdmissionRedisDump::pttl($this->executor);
    }

    /**
     * True when no key's TTL grew since `$before` (a refresh would raise a PTTL).
     *
     * @param array<string, int> $before
     */
    protected function noTtlRefreshed(array $before): bool
    {
        foreach ($this->pttl() as $key => $pttl) {
            if (isset($before[$key]) && $pttl > $before[$key]) {
                return false;
            }
        }

        return true;
    }

    protected function raw(string ...$command): mixed
    {
        /** @var non-empty-list<string> $command */
        return $this->executor->execute($command);
    }

    protected function executorFor(RedisCommandExecutorInterface $executor): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore($executor, self::NAMESPACE);
    }
}
