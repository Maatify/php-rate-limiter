<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\FailureFallbackProfileEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Boundaries of the device Anti-Equilibrium watcher (DEC-021 PROPOSED):
 * F117-FR-01 — it belongs only to budgeted, non-API authentication policies
 * that score K5; F117-FR-02 — every issued SOFT_BLOCK performs exactly one
 * watcher write (account for any account source, device for K5-only).
 */
final class KnownDeviceAntiEquilibriumBoundaryTest extends TestCase
{
    private FixedClock $clock;
    private WatchFaultStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new WatchFaultStore($this->clock);
    }

    /**
     * FR-01: an API_OVERUSE recordFailure with an account and a fingerprint,
     * and with three device events already stored under its policy name,
     * neither reads the device watcher nor persists anything on K5.
     */
    public function testApiOveruseFailureNeverReadsOrEscalatesTheDeviceWatcher(): void
    {
        $this->seedDeviceEvents('api_heavy_protection', 'api-owner', 'api-fp', 3);
        $this->store->failWatchReads = true;

        $result = $this->pipeline()->process(new ApiHeavyProtectionPolicy(), $this->context('api-owner'), RateLimitCommand::recordFailure('api_heavy_protection'), $this->device('api-fp', true));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertNull($this->store->checkBlock($this->key('api_heavy_protection', 'k5', 'api-owner:api-fp')));
    }

    /**
     * FR-01: same-name migration. Device events written while the name served
     * the Login preset must not escalate once a DEC-008 replacement under the
     * same name is an API_OVERUSE policy.
     */
    public function testSameNameApiReplacementIgnoresPreexistingDeviceEvents(): void
    {
        $this->seedDeviceEvents('login_protection', 'migrated-owner', 'migrated-fp', 3);
        $this->store->failWatchReads = true;

        $replacement = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'login_protection';
            }
        };
        $result = $this->pipeline()->process($replacement, $this->context('migrated-owner'), RateLimitCommand::recordFailure('login_protection'), $this->device('migrated-fp', true));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertNull($this->store->checkBlock($this->key('login_protection', 'k5', 'migrated-owner:migrated-fp')));
    }

    /**
     * FR-01: a custom policy without a budget (the account watcher is off for
     * it) gets no device watcher either, even with K5 thresholds and events.
     */
    public function testCustomPolicyWithoutBudgetGetsNoDeviceWatcher(): void
    {
        $this->seedDeviceEvents('no_budget_flow', 'nobudget-owner', 'nobudget-fp', 3);
        $this->store->failWatchReads = true;

        $result = $this->pipeline()->process(new NoBudgetK5Policy(), $this->context('nobudget-owner'), RateLimitCommand::recordFailure('no_budget_flow'), $this->device('nobudget-fp', true));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertNull($this->store->checkBlock($this->key('no_budget_flow', 'k5', 'nobudget-owner:nobudget-fp')));
    }

    /**
     * FR-01 positive control: an eligible authentication policy still reads
     * the device watcher, and a read fault is a typed backend failure.
     */
    public function testEligiblePolicyStillReadsTheDeviceWatcher(): void
    {
        $this->store->failWatchReads = true;
        $this->expectException(BackendFailureException::class);

        $this->pipeline()->process(new LoginProtectionPolicy(), $this->context('eligible-owner'), RateLimitCommand::recordFailure('login_protection'), $this->device('eligible-fp', true));
    }

    /**
     * FR-02: a mixed device + account SOFT_BLOCK performs exactly one watcher
     * write, on the account watcher; the device watcher is never written for
     * it (a fault injected on the device watcher is not even reached).
     */
    public function testMixedSoftWritesOnlyTheAccountWatcher(): void
    {
        $pipeline = $this->pipeline();
        $policy = new MixedFaultPolicy();
        $device = $this->device('mixed-fp');
        $pipeline->process($policy, $this->context('mixed-owner'), RateLimitCommand::recordFailure('mixed_fault_flow'), $device);
        $this->assertSame([0, 1], $this->watchers('mixed-owner', 'mixed-fp'));

        $this->store->failWritesTo = [$this->deviceWatcher('mixed_fault_flow', 'mixed-owner', 'mixed-fp')];
        $mixed = $pipeline->process($policy, $this->context('mixed-owner'), RateLimitCommand::recordFailure('mixed_fault_flow'), $device);

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $mixed->decision);
        $this->assertSame([1, 1], $this->watchers('mixed-owner', 'mixed-fp'));
    }

    /**
     * FR-02: when the single selected (account) write of a mixed SOFT fails,
     * no event of any scope is left behind.
     */
    public function testMixedSoftWithFailingAccountWriteLeavesNoEvent(): void
    {
        $pipeline = $this->pipeline();
        $policy = new MixedFaultPolicy();
        $device = $this->device('reverse-fp');
        $pipeline->process($policy, $this->context('reverse-owner'), RateLimitCommand::recordFailure('mixed_fault_flow'), $device);
        $before = $this->watchers('reverse-owner', 'reverse-fp');

        $this->store->failWritesTo = [$this->accountWatcher('mixed_fault_flow', 'reverse-owner')];
        try {
            $pipeline->process($policy, $this->context('reverse-owner'), RateLimitCommand::recordFailure('mixed_fault_flow'), $device);
            self::fail('Expected the account watcher write to fail.');
        } catch (BackendFailureException) {
        }

        $this->assertSame([0, 1], $before);
        $this->assertSame($before, $this->watchers('reverse-owner', 'reverse-fp'));
    }

    /**
     * FR-02 through limit(): with the single account write of mixed requests
     * failing, the engine answers through its existing typed failure path, no
     * watcher event is published, no account-wide HARD exists, and the owner's
     * other device is not hard blocked.
     */
    public function testMixedSoftWriteFaultThroughPublicApiLeavesNoWatcherEvent(): void
    {
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'), $this->store, new RecordingFailureSignalEmitter())
            ->withClock($this->clock)
            ->withPolicy(new MixedFaultPolicy())
            ->build();
        $context = new RateLimitContextDTO('198.51.100.90', 'Chrome/123', 'engine-owner', ['canvas' => 'engine-laptop'], 'engine-session', true);
        $deviceWatcher = $this->engineDeviceWatcher($limiter, $context);
        $accountWatcher = hash_hmac('sha256', 'mixed_fault_flow:rate_limiter:aux:anti_equilibrium:v1:prod:engine-owner', 'key-secret');

        $first = $limiter->limit($context, RateLimitCommand::recordFailure('mixed_fault_flow'));
        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
        $this->assertSame(1, $this->store->getWatchFlag($deviceWatcher));

        $this->store->failWritesTo = [$accountWatcher];
        $faulted = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $limiter->limit($context, RateLimitCommand::recordFailure('mixed_fault_flow'));
            $faulted[] = [$result->decision, $result->failureMode];
        }

        // DEC-015 unchanged: two typed failures fail closed, then the open
        // circuit serves the bounded local fallback without the pipeline.
        $this->assertSame([
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 'FAIL_CLOSED'],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 'FAIL_CLOSED'],
            [RateLimitResultDTO::DECISION_ALLOW, 'DEGRADED_MODE'],
        ], $faulted);
        $this->assertSame(0, $this->store->getWatchFlag($accountWatcher));
        $this->assertSame(1, $this->store->getWatchFlag($deviceWatcher));
        // The faulted request's own K4 L1 score persistence is written before
        // the watcher write fails (the pipeline is not transactional across
        // store operations, as on S0). L1 never denies; no account-wide HARD.
        $this->assertSame(1, $this->store->checkBlock(hash_hmac('sha256', 'mixed_fault_flow:rate_limiter:k4:v2:prod:engine-owner', 'key-secret'))?->level);
        $after = $limiter->limit(
            new RateLimitContextDTO('198.51.100.90', 'Chrome/123', 'engine-owner', ['canvas' => 'engine-phone'], 'engine-session-2', true),
            RateLimitCommand::checkOnly('mixed_fault_flow'),
        );
        $this->assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $after->decision);
    }

    private function engineDeviceWatcher(object $limiter, RateLimitContextDTO $context): string
    {
        $resolver = new \Maatify\RateLimiter\Service\DeviceIdentityResolver(new \Maatify\RateLimiter\Service\FingerprintHasher('fingerprint-secret'));
        $hash = $resolver->resolve($context)->fingerprintHash;
        self::assertIsString($hash);

        return hash_hmac('sha256', "mixed_fault_flow:rate_limiter:aux:anti_equilibrium_device:v1:prod:engine-owner:{$hash}", 'key-secret');
    }

    /**
     * Reads live store state; the value changes between calls.
     *
     * @phpstan-impure
     * @return list<int> [account watcher, device watcher]
     */
    private function watchers(string $account, string $fingerprint): array
    {
        return [
            $this->store->getWatchFlag($this->accountWatcher('mixed_fault_flow', $account)),
            $this->store->getWatchFlag($this->deviceWatcher('mixed_fault_flow', $account, $fingerprint)),
        ];
    }

    private function seedDeviceEvents(string $policy, string $account, string $fingerprint, int $events): void
    {
        for ($event = 0; $event < $events; $event++) {
            $this->store->incrementWatchFlag($this->deviceWatcher($policy, $account, $fingerprint), 21600);
        }
    }

    private function pipeline(): EvaluationPipeline
    {
        return new EvaluationPipeline($this->store, $this->store, new BudgetTracker($this->store, $this->clock), new AntiEquilibriumGate($this->store), new DecayCalculator($this->clock), new EphemeralBucket($this->store), 'test_secret', 'prod', $this->clock, null);
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.' . (crc32($account) % 250 + 1), 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint, bool $trusted = false): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $trusted ? 'HIGH' : 'MEDIUM', $trusted, false, 'chrome/123', ! $trusted);
    }

    private function key(string $policy, string $type, string $scope): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", 'test_secret');
    }

    private function accountWatcher(string $policy, string $account): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:aux:anti_equilibrium:v1:prod:{$account}", 'test_secret');
    }

    private function deviceWatcher(string $policy, string $account, string $fingerprint): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:aux:anti_equilibrium_device:v1:prod:{$account}:{$fingerprint}", 'test_secret');
    }
}

/**
 * Full-capability reference store with injectable watcher faults.
 */
final class WatchFaultStore extends FullCapabilityInMemoryStore
{
    public bool $failWatchReads = false;

    /** @var list<string> */
    public array $failWritesTo = [];

    public function getWatchFlag(string $key): int
    {
        if ($this->failWatchReads) {
            throw new BackendFailureException('watch read fault');
        }

        return parent::getWatchFlag($key);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        if (in_array($key, $this->failWritesTo, true)) {
            throw new BackendFailureException('watch write fault');
        }

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }
}

/**
 * K5 L1 at the first known-device failure, K4 L1 at the first K4-scored
 * failure, allowance 1: the second known-device failure issues one mixed
 * device + account SOFT_BLOCK.
 */
final class MixedFaultPolicy implements KnownDeviceAccountAllowancePolicyInterface, FailureFallbackConfigurationProviderInterface
{
    public function getName(): string
    {
        return 'mixed_fault_flow';
    }

    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return FailureFallbackProfileEnum::AUTHENTICATION_PRIMARY->configuration();
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(3, 100, 200), k5: new ScoreThresholdsDTO(2, 100, 200));
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k2_missing_fp: 4, k4_failure: 3, k5_failure: 2);
    }

    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }

    public function getBudgetConfig(): BudgetConfigDTO
    {
        return new BudgetConfigDTO(1000, 3);
    }

    public function getKnownDeviceAccountAllowance(): int
    {
        return 1;
    }
}

/**
 * Custom policy with K5 thresholds but no budget: Anti-Equilibrium is off.
 */
final class NoBudgetK5Policy implements BlockPolicyInterface
{
    public function getName(): string
    {
        return 'no_budget_flow';
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(100, 200, 300), k5: new ScoreThresholdsDTO(100, 200, 300));
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
