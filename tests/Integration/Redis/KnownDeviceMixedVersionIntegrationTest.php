<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;

/**
 * Mixed-version regression for the known-device bound on real Redis (F117-06,
 * DEC-021 PROPOSED). The old worker is the pinned S0 pipeline
 * (S0EvaluationPipeline, provenance-checked) with the S0 Login preset; the new
 * worker is the current pipeline. Both share one real Redis namespace owned by
 * the test. Redis owns time for its primitives (DEC-016), so the workers'
 * clock reads Redis TIME; cases that need time to pass (rollback after decay,
 * previous generation) run in KnownDeviceMixedVersionSystemTest. Each case
 * states whether it asserts an invariant or an expected semantic difference.
 */
final class KnownDeviceMixedVersionIntegrationTest extends TestCase
{
    private const STORE_PREFIX = 'maatify:rate-limiter:v1';

    private RespRedisCommandExecutor $executor;
    private RedisFullCapabilityStore $store;
    private ClockInterface $clock;
    private string $namespace;
    private string $sentinel;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $portValue);
        $run = bin2hex(random_bytes(8));
        $this->namespace = 'it-known-device-mixed-' . $run;
        $this->sentinel = 'test:known-device-mixed-foreign:' . $run;
        $this->executor->execute(['SET', $this->sentinel, 'untouched', 'EX', '600']);
        $this->store = new RedisFullCapabilityStore($this->executor, $this->namespace);
        $this->clock = new RedisTimeClock($this->executor);
    }

    protected function tearDown(): void
    {
        if (! isset($this->executor)) {
            return;
        }
        self::assertSame('untouched', $this->executor->execute(['GET', $this->sentinel]));
        $cursor = '0';
        do {
            $reply = $this->executor->execute(['SCAN', $cursor, 'MATCH', self::STORE_PREFIX . ':' . hash('sha256', $this->namespace) . ':*', 'COUNT', '500']);
            self::assertIsArray($reply);
            self::assertIsString($reply[0] ?? null);
            self::assertIsArray($reply[1] ?? null);
            $cursor = $reply[0];
            foreach ($reply[1] as $key) {
                self::assertIsString($key);
                $this->executor->execute(['DEL', $key]);
            }
        } while ($cursor !== '0');
        $this->executor->execute(['DEL', $this->sentinel]);
    }

    /**
     * M1 — expected semantic difference. S0 persists a level derived from K5
     * on the account key K4. The new worker honors that existing K4 block (the
     * owner's other device is denied until it expires), although the new
     * worker on its own blocks only the offending device.
     */
    public function testNewWorkerHonorsAnS0AccountBlockDerivedFromK5(): void
    {
        $policy = new CustomK5Policy();
        $s0 = $this->s0Worker();
        $decisions = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decisions[] = $this->failure($s0, $policy, 'm1-owner', $this->device('m1-laptop'))->decision;
        }
        self::assertSame(['ALLOW', 'SOFT_BLOCK', 'HARD_BLOCK'], $decisions);
        self::assertSame(2, $this->store->checkBlock($this->key('custom_k5_flow', 'k4', 'm1-owner'))?->level);
        self::assertNull($this->store->checkBlock($this->key('custom_k5_flow', 'k5', 'm1-owner:m1-laptop')));

        self::assertSame('HARD_BLOCK', $this->check($this->newWorker(), $policy, 'm1-owner', $this->device('m1-phone'))->decision);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->failure($this->newWorker(), $policy, 'm1-new-owner', $this->device('m1-laptop'));
        }
        self::assertNull($this->store->checkBlock($this->key('custom_k5_flow', 'k4', 'm1-new-owner')));
        self::assertSame(2, $this->store->checkBlock($this->key('custom_k5_flow', 'k5', 'm1-new-owner:m1-laptop'))?->level);
        self::assertSame('ALLOW', $this->check($this->newWorker(), $policy, 'm1-new-owner', $this->device('m1-phone'))->decision);
    }

    /**
     * M2 — invariant. A K5 block written by the new worker is honored by the
     * S0 worker for that device only.
     */
    public function testS0WorkerHonorsANewK5BlockForThatDeviceOnly(): void
    {
        $new = $this->newWorker();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->failure($new, new LoginProtectionPolicy(), 'm2-owner', $this->device('m2-laptop', true));
        }
        self::assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k5', 'm2-owner:m2-laptop'))?->level);

        $s0 = $this->s0Worker();
        self::assertSame('HARD_BLOCK', $this->check($s0, new S0LoginProtectionPolicy(), 'm2-owner', $this->device('m2-laptop', true))->decision);
        self::assertSame('ALLOW', $this->check($s0, new S0LoginProtectionPolicy(), 'm2-owner', $this->device('m2-phone', true))->decision);
    }

    /**
     * M3 — observed mixed fleet. Alternating S0 / new workers per request on
     * the #114 burst (one trusted session failing on its own account) reaches
     * the verifier more often than the new worker alone and less often than
     * S0 alone. The exact count pins the observed interleaving.
     */
    public function testAlternatingWorkersOnTheTrustedBurst(): void
    {
        $reached = ['s0' => 0, 'new' => 0, 'mixed' => 0];
        foreach (['s0', 'new', 'mixed'] as $fleet) {
            $account = 'm3-' . $fleet;
            for ($attempt = 0; $attempt < 25; $attempt++) {
                $useS0 = $fleet === 's0' || ($fleet === 'mixed' && $attempt % 2 === 0);
                $worker = $useS0 ? $this->s0Worker() : $this->newWorker();
                $policy = $useS0 ? new S0LoginProtectionPolicy() : new LoginProtectionPolicy();
                if ($this->check($worker, $policy, $account, $this->device('m3-session', true))->decision === RateLimitResultDTO::DECISION_ALLOW) {
                    $reached[$fleet]++;
                    $this->failure($worker, $policy, $account, $this->device('m3-session', true));
                }
            }
        }

        self::assertSame(['s0' => 25, 'new' => 4, 'mixed' => 15], $reached);
    }

    private function check(EvaluationPipeline|S0EvaluationPipeline $worker, BlockPolicyInterface $policy, string $account, DeviceIdentityDTO $device): RateLimitResultDTO
    {
        return $worker->process($policy, $this->context($account), RateLimitCommand::checkOnly($policy->getName()), $device);
    }

    private function failure(EvaluationPipeline|S0EvaluationPipeline $worker, BlockPolicyInterface $policy, string $account, DeviceIdentityDTO $device): RateLimitResultDTO
    {
        return $worker->process($policy, $this->context($account), RateLimitCommand::recordFailure($policy->getName()), $device);
    }

    private function newWorker(string $secret = 'test_secret', ?string $previousSecret = null): EvaluationPipeline
    {
        return new EvaluationPipeline($this->store, $this->store, new BudgetTracker($this->store, $this->clock), new AntiEquilibriumGate($this->store), new DecayCalculator($this->clock), new EphemeralBucket($this->store), $secret, 'prod', $this->clock, $previousSecret);
    }

    private function s0Worker(string $secret = 'test_secret', ?string $previousSecret = null): S0EvaluationPipeline
    {
        return new S0EvaluationPipeline($this->store, $this->store, new BudgetTracker($this->store, $this->clock), new AntiEquilibriumGate($this->store), new DecayCalculator($this->clock), new EphemeralBucket($this->store), $secret, 'prod', $this->clock, $previousSecret);
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.80', 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint, bool $trusted = false, bool $known = true): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $trusted ? 'HIGH' : 'MEDIUM', $trusted, false, 'chrome/123', $known);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

}

/**
 * Custom authentication-shaped policy with K5 thresholds and no allowance
 * opt-in: on S0 its K5 level is persisted on K4; on the new worker on K5.
 */
final class CustomK5Policy implements BlockPolicyInterface
{
    public function getName(): string
    {
        return 'custom_k5_flow';
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(100, 200, 300), k5: new ScoreThresholdsDTO(4, 6, 8));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k4_failure: 3, k5_failure: 2);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return null;
    }
}

/**
 * Clock that follows Redis TIME on every read, as the Redis primitives do.
 */
final class RedisTimeClock implements ClockInterface
{
    public function __construct(private readonly RespRedisCommandExecutor $executor) {}

    public function now(): \DateTimeImmutable
    {
        $time = $this->executor->execute(['TIME']);
        if (! is_array($time) || ! is_string($time[0] ?? null)) {
            throw new \RuntimeException('Redis TIME reply is malformed.');
        }

        return new \DateTimeImmutable('@' . $time[0]);
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
