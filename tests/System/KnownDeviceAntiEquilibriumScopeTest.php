<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * Source/scope-aware Anti-Equilibrium for known devices (F117-05, DEC-021
 * PROPOSED). A SOFT_BLOCK derived only from K5 is a device-scoped event; it
 * escalates on that account + device (K5), not on the whole account (K4).
 * Every other issued SOFT_BLOCK keeps the ACTIVE account-wide §2.5 behavior.
 */
final class KnownDeviceAntiEquilibriumScopeTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private ThrowingWatchCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new ThrowingWatchCorrelationStore($this->clock);
    }

    /**
     * (i) Four typos, three retries during the K5 soft window, one more typo
     * after decay: the offending device is hard blocked on K5; the owner's
     * other device is not blocked on K4 by those device-only events.
     */
    public function testK5OnlySoftRetriesEscalateTheDeviceNotTheAccount(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $laptop = $this->device('owner-laptop', 'HIGH', true);

        $this->typosThenSoftRetries($pipeline, $policy, 'scope-owner', $laptop);
        $this->advance(300);
        $this->assertTrue($this->preThenFailure($pipeline, $policy, 'scope-owner', $laptop));

        $this->assertNull($this->store->checkBlock($this->key('login_protection', 'k4', 'scope-owner')));
        $this->assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k5', 'scope-owner:owner-laptop'))?->level);
        $this->assertSame(0, $this->correlationStore->getWatchFlag($this->accountWatcher('login_protection', 'scope-owner')));
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $this->check($pipeline, $policy, 'scope-owner', $laptop)->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->check($pipeline, $policy, 'scope-owner', $this->device('owner-phone', 'HIGH', true))->decision);
    }

    /**
     * (ii) An attacker that keeps retrying on the offending device every
     * minute stays capped there: each further failure re-escalates on K5
     * (HARD L2), and at most four attempts reach the verifier in an hour.
     */
    public function testRepeatedRetriesStayCappedOnTheOffendingDevice(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $stolen = $this->device('stolen-session', 'HIGH', true);

        $this->typosThenSoftRetries($pipeline, $policy, 'scope-victim', $stolen);
        $failures = [];
        for ($minute = 0; $minute < 60; $minute++) {
            $this->advance(60);
            if ($this->check($pipeline, $policy, 'scope-victim', $stolen)->decision === RateLimitResultDTO::DECISION_ALLOW) {
                $failures[] = $pipeline->process($policy, $this->context('scope-victim'), RateLimitCommand::recordFailure('login_protection'), $stolen);
            }
        }

        $this->assertLessThanOrEqual(4, count($failures));
        $this->assertNotSame([], $failures);
        foreach ($failures as $failure) {
            $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $failure->decision);
            $this->assertSame(2, $failure->blockLevel);
        }
    }

    /**
     * (iii) A genuinely account-scoped SOFT (K4 score) still feeds the account
     * watcher and still escalates to an account-wide K4 HARD.
     */
    public function testAccountScopedSoftStillEscalatesOnK4(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $this->store->set($this->key('login_protection', 'k4', 'account-owner'), 5, 86400);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $this->check($pipeline, $policy, 'account-owner', $this->device('new-device'))->decision);
        }
        $this->assertSame(3, $this->correlationStore->getWatchFlag($this->accountWatcher('login_protection', 'account-owner')));

        $this->store->set($this->key('login_protection', 'k4', 'account-owner'), 0, 86400);
        $failure = $pipeline->process($policy, $this->context('account-owner'), RateLimitCommand::recordFailure('login_protection'), $this->device('new-device'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $failure->decision);
        $this->assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k4', 'account-owner'))?->level);
    }

    /**
     * (iv) An issued SOFT that aggregates a K5 level and an account level is
     * recorded once, on the ACCOUNT watcher only (one write per issued SOFT,
     * account protection preserved); the device watcher is not touched. Shown
     * for both candidate shapes: one aggregated candidate (previously verified
     * device) and separate K4 / K5 candidates (trusted session).
     */
    public function testMixedK5AndAccountSoftIsRecordedOnTheAccountWatcherOnly(): void
    {
        foreach (['aggregated' => false, 'separate' => true] as $shape => $trusted) {
            // Fresh state per shape: two fingerprints on one IP + user agent
            // would otherwise meet through K2 churn.
            $this->setUp();
            $pipeline = $this->pipeline();
            $policy = new MixedScopePolicy();
            $account = "mixed-{$shape}";
            $device = $this->device("mixed-{$shape}-fp", $trusted ? 'HIGH' : 'MEDIUM', $trusted, ! $trusted);

            $first = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('mixed_scope_flow'), $device);
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $first->decision);
            $this->assertSame([0, 1], $this->watchers('mixed_scope_flow', $account, "mixed-{$shape}-fp"), $shape);

            $second = $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('mixed_scope_flow'), $device);
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $second->decision);
            $this->assertSame([1, 1], $this->watchers('mixed_scope_flow', $account, "mixed-{$shape}-fp"), $shape);
        }
    }

    /**
     * (iv) Three mixed SOFT events still escalate on the account (K4 L2), as
     * the ACTIVE §2.5 account gate requires.
     */
    public function testThreeMixedSoftEventsStillEscalateTheAccount(): void
    {
        $pipeline = $this->pipeline();
        $policy = new MixedScopePolicy();
        $device = $this->device('mixed-escalation-fp', 'MEDIUM', false, true);

        $decisions = [];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $decisions[] = $pipeline->process($policy, $this->context('mixed-escalation'), RateLimitCommand::recordFailure('mixed_scope_flow'), $device)->decision;
        }

        $this->assertSame(['SOFT_BLOCK', 'SOFT_BLOCK', 'SOFT_BLOCK', 'SOFT_BLOCK', 'HARD_BLOCK'], $decisions);
        $this->assertSame([3, 1], $this->watchers('mixed_scope_flow', 'mixed-escalation', 'mixed-escalation-fp'));
        $this->assertSame(2, $this->store->checkBlock($this->key('mixed_scope_flow', 'k4', 'mixed-escalation'))?->level);
    }

    /**
     * @return list<int> [account watcher, device watcher]
     */
    private function watchers(string $policy, string $account, string $fingerprint): array
    {
        return [
            $this->correlationStore->getWatchFlag($this->accountWatcher($policy, $account)),
            $this->correlationStore->getWatchFlag($this->deviceWatcher($policy, $account, $fingerprint)),
        ];
    }

    /**
     * (v) The device watcher follows outer-secret rotation: events written
     * under the previous secret still escalate under the current one, and the
     * previous watcher stays read-only.
     */
    public function testDeviceWatcherSurvivesOuterSecretRotation(): void
    {
        $policy = new LoginProtectionPolicy();
        $laptop = $this->device('rotating-laptop', 'HIGH', true);
        $this->typosThenSoftRetries($this->pipeline(), $policy, 'rotating-owner', $laptop);
        $before = $this->correlationStore->getWatchFlag($this->deviceWatcher('login_protection', 'rotating-owner', 'rotating-laptop'));

        $this->advance(300);
        $rotated = $this->pipeline('test_secret', 'new_secret');
        $this->assertTrue($this->preThenFailure($rotated, $policy, 'rotating-owner', $laptop));

        $this->assertGreaterThanOrEqual(3, $before);
        $this->assertSame($before, $this->correlationStore->getWatchFlag($this->deviceWatcher('login_protection', 'rotating-owner', 'rotating-laptop')));
        $this->assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k5', 'rotating-owner:rotating-laptop', 'new_secret'))?->level);
        $this->assertNull($this->store->checkBlock($this->key('login_protection', 'k4', 'rotating-owner', 'new_secret')));
    }

    /**
     * (v) DEC-015: a backend failure while reading the device watcher is a
     * typed backend failure, never a silent normal-mode allowance.
     */
    public function testDeviceWatcherBackendFailurePropagates(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $laptop = $this->device('outage-laptop', 'HIGH', true);
        $this->typosThenSoftRetries($pipeline, $policy, 'outage-owner', $laptop);
        $this->advance(300);

        $this->correlationStore->failWatchReads = true;
        $this->expectException(BackendFailureException::class);
        $pipeline->process($policy, $this->context('outage-owner'), RateLimitCommand::recordFailure('login_protection'), $laptop);
    }

    private function typosThenSoftRetries(EvaluationPipeline $pipeline, LoginProtectionPolicy $policy, string $account, DeviceIdentityDTO $device): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertTrue($this->preThenFailure($pipeline, $policy, $account, $device));
        }
        for ($retry = 0; $retry < 3; $retry++) {
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $this->check($pipeline, $policy, $account, $device)->decision);
        }
    }

    private function preThenFailure(EvaluationPipeline $pipeline, LoginProtectionPolicy $policy, string $account, DeviceIdentityDTO $device): bool
    {
        if ($this->check($pipeline, $policy, $account, $device)->decision !== RateLimitResultDTO::DECISION_ALLOW) {
            return false;
        }
        $pipeline->process($policy, $this->context($account), RateLimitCommand::recordFailure('login_protection'), $device);

        return true;
    }

    private function check(EvaluationPipeline $pipeline, LoginProtectionPolicy $policy, string $account, DeviceIdentityDTO $device): RateLimitResultDTO
    {
        return $pipeline->process($policy, $this->context($account), RateLimitCommand::checkOnly('login_protection'), $device);
    }

    /**
     * FixedClock::sleep() is a no-op; time must be advanced explicitly.
     */
    private function advance(int $seconds): void
    {
        $this->clock->setNow($this->clock->now()->modify("+{$seconds} seconds"));
    }

    private function pipeline(?string $previousSecret = null, string $currentSecret = 'test_secret'): EvaluationPipeline
    {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $currentSecret,
            'prod',
            $this->clock,
            $previousSecret,
        );
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.40', 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint, string $confidence = 'MEDIUM', bool $trusted = false, bool $previouslyVerified = false): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $confidence, $trusted, false, 'chrome/123', $previouslyVerified);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function accountWatcher(string $policy, string $account): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:aux:anti_equilibrium:v1:prod:{$account}", 'test_secret');
    }

    private function deviceWatcher(string $policy, string $account, string $fingerprint, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:aux:anti_equilibrium_device:v1:prod:{$account}:{$fingerprint}", $secret);
    }
}

/**
 * K5 thresholds reach L1 at the first known-device failure and K4 thresholds
 * reach L1 at the first K4-scored failure; an allowance of 1 makes the second
 * known-device failure scored on both, producing one mixed SOFT_BLOCK.
 */
final class MixedScopePolicy implements KnownDeviceAccountAllowancePolicyInterface
{
    public function getName(): string
    {
        return 'mixed_scope_flow';
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

final class ThrowingWatchCorrelationStore extends StatefulInMemoryCorrelationStore
{
    public bool $failWatchReads = false;

    public function getWatchFlag(string $key): int
    {
        if ($this->failWatchReads) {
            throw new BackendFailureException('watch backend down');
        }

        return parent::getWatchFlag($key);
    }
}
