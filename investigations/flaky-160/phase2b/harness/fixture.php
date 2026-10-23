<?php

declare(strict_types=1);

// #160 phase 2b — the test's own fixture, rebuilt for the harness scripts (A-DET, V-CLOCK-2, overhead):
// the pipeline exactly as KnownDeviceAllowanceConcurrencyIntegrationTest::pipeline() builds it, the same
// policy, context and device (L100-L130, L257-L296 of the test at main@2cf8565).

namespace Flaky160B;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

final class Fixture
{
    public static function executor(int $port): RespRedisCommandExecutor
    {
        return new RespRedisCommandExecutor('127.0.0.1', $port);
    }

    public static function redisNow(RespRedisCommandExecutor $e): int
    {
        $t = $e->execute(['TIME']);
        return (int) $t[0];
    }

    public static function pipeline(RedisFullCapabilityStore $store, int $now): EvaluationPipeline
    {
        $clock = new FixedClock('@' . $now);

        return new EvaluationPipeline(
            $store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock),
            new EphemeralBucket($store), 'test_secret', 'prod', $clock, null,
        );
    }

    public static function command(EvaluationPipeline $pipeline, string $fp = 'concurrent-fp'): RateLimitResultDTO
    {
        return $pipeline->process(
            new LoginProtectionPolicy(),
            new RateLimitContextDTO('198.51.100.60', 'Chrome/123', 'login-owner', ['device' => 'stable']),
            RateLimitCommand::recordFailure('login_protection'),
            new DeviceIdentityDTO($fp, 'HIGH', true, false, 'chrome/123', false),
        );
    }

    /**
     * Harness-made pre-state through the store's own public methods (A-DET, V-CLOCK-2): the allowance at its
     * limit (8) and the K4 score at 6, so the next failure scores K4 to 9 (>= l2 = 8) and reaches the
     * publication branch. A concurrent run reaches it through 16 overlapping workers; a sequential one cannot,
     * because the block of the first failures answers the rest early (probe: HARD_BLOCK from the third command).
     */
    public static function preState(RedisFullCapabilityStore $store, int $now): void
    {
        $store->incrementBudgetAt(Keys::allowance(), 86400, 8, $now);
        $store->mutateGenerationBoundScoreAt(Keys::k4(), null, null, 86400, 6, $now * 1000);
    }

    /** @return array{allowance: int, k4: int, k5: int, k4_block: ?int, k5_block: ?int} */
    public static function state(RedisFullCapabilityStore $store): array
    {
        return [
            'allowance' => $store->getBudget(Keys::allowance())->count ?? 0,
            'k4' => $store->get(Keys::k4())->value ?? 0,
            'k5' => $store->get(Keys::k5())->value ?? 0,
            'k4_block' => $store->checkBlock(Keys::k4())?->level,
            'k5_block' => $store->checkBlock(Keys::k5())?->level,
        ];
    }

    public static function cleanup(RespRedisCommandExecutor $e, string $namespace): void
    {
        $cursor = '0';
        do {
            $r = $e->execute(['SCAN', $cursor, 'MATCH', 'maatify:rate-limiter:v1:' . hash('sha256', $namespace) . ':*', 'COUNT', '500']);
            $cursor = (string) $r[0];
            if ($r[1] !== []) {
                $e->execute(['DEL', ...$r[1]]);
            }
        } while ($cursor !== '0');
    }
}
