<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

/** Public runtime fixture shared by the Redis gate and its independent worker processes. */
final class PublicRedisAdmissionFixture
{
    public const BINDING_SECRET = 'redis-gate-binding';

    public static function build(RedisCommandExecutorInterface $executor, string $namespace, int $now, int $retryBudgetMilliseconds = 500, ?int $pendingLimit = null, ?FixedClock $clock = null, ?RateLimiterConfig $config = null): CompositeRateLimiterRuntimeInterface
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $config ?? new RateLimiterConfig('redis-gate-key', 'redis-gate-fingerprint', 'test', bindingKeySecret: self::BINDING_SECRET),
            new RedisFullCapabilityStore($executor, $namespace, null, $retryBudgetMilliseconds),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock ?? new FixedClock('@' . $now))->withPolicy(new LoginProtectionAdmissionPolicy());
        if ($pendingLimit !== null) {
            $builder->withProvenPendingPermitLimit($pendingLimit);
        }

        return $builder->build();
    }
}
