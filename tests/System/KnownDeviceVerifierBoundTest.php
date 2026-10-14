<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
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
use Maatify\RateLimiter\Tests\Support\RateLimiter\PartialFailureRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifier-reach bounds for known and trusted devices on authentication
 * policies (#114 hardening slice of #110).
 *
 * "Reached" counts a pre-check that returned ALLOW, i.e. an attempt the Host
 * forwards to its credential verifier. Every failing attempt in these
 * streams is followed by recordFailure only when the pre-check allowed it
 * (the documented pre+out lifecycle).
 */
final class KnownDeviceVerifierBoundTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    /**
     * The #114 control: one contract-compliant trusted session failing on its
     * own account every 3 s for 72 s. S0 lets all 25 attempts reach the verifier.
     */
    public function testLoginTrustedSessionOnOwnAccountReachesVerifierFourTimesInBurst(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $device = $this->device('trusted-fp', 'HIGH', true);

        $reached = 0;
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $reached += $this->preThenFailure($pipeline, $policy, $this->context('owner'), $device) ? 1 : 0;
            $this->advance(3);
        }

        // The L1 decision comes from the K5 score (8), which decays one point
        // per 300 s; the short L1 block record itself expires after 15 s.
        $this->assertSame(4, $reached);
        $this->assertSame(8, $this->store->get($this->key('login_protection', 'k5', 'owner:trusted-fp'))?->value);
        $this->assertNull($this->store->checkBlock($this->key('login_protection', 'k4', 'owner')));
        $this->assertNull($this->store->get($this->key('login_protection', 'k4', 'owner')));
    }

    public function testOtpTrustedSessionOnOwnAccountReachesVerifierTwiceInBurst(): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $device = $this->device('trusted-fp', 'HIGH', true);

        $reached = 0;
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $reached += $this->preThenFailure($pipeline, $policy, $this->context('owner'), $device) ? 1 : 0;
            $this->advance(3);
        }

        $this->assertSame(2, $reached);
        $this->assertNull($this->store->checkBlock($this->key('otp_protection', 'k4', 'owner')));
    }

    /**
     * A known-device hard level is device scoped: the owner's other device on
     * the same account keeps access, and K4 carries no block.
     */
    public function testK5HardLevelPersistsOnDeviceKeyAndOwnerOtherDeviceStaysAllowed(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $stolen = $this->device('stolen-session-fp', 'HIGH', true);

        $last = null;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $last = $pipeline->process($policy, $this->context('owner'), RateLimitCommand::recordFailure('login_protection'), $stolen);
        }

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $last->decision);
        $this->assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k5', 'owner:stolen-session-fp'))?->level);
        $this->assertNull($this->store->checkBlock($this->key('login_protection', 'k4', 'owner')));

        $blocked = $pipeline->process($policy, $this->context('owner'), RateLimitCommand::checkOnly('login_protection'), $stolen);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blocked->decision);

        $ownerPhone = $pipeline->process(
            $policy,
            $this->context('owner'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device('owner-phone-fp', 'HIGH', true),
        );
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $ownerPhone->decision);
    }

    /**
     * A custom policy that already defines K5 thresholds must not have a
     * device-scoped level converted into an account-wide K4 block.
     */
    public function testCustomPolicyK5LevelNeverLocksTheAccountKey(): void
    {
        $pipeline = $this->pipeline();
        $policy = $this->customPolicy(new ScoreThresholdsDTO(4, 6, 8));
        $device = $this->device('custom-fp', 'MEDIUM', false, true);

        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $last = $pipeline->process($policy, $this->context('custom-owner'), RateLimitCommand::recordFailure('custom_authentication_flow'), $device);
        }

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $last->decision);
        $this->assertSame(2, $this->store->checkBlock($this->key('custom_authentication_flow', 'k5', 'custom-owner:custom-fp'))?->level);
        $this->assertNull($this->store->checkBlock($this->key('custom_authentication_flow', 'k4', 'custom-owner')));

        $otherDevice = $pipeline->process(
            $policy,
            $this->context('custom-owner'),
            RateLimitCommand::checkOnly('custom_authentication_flow'),
            $this->device('custom-other-fp', 'MEDIUM', false, true),
        );
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $otherDevice->decision);
    }

    /**
     * A stable trusted device pacing one failure every 600 s for 24 h. S0
     * lets 128 of 144 attempts reach the verifier (an unknown device on the
     * same stream: 39). After the account allowance, a known device must be
     * bounded no worse than an unknown device plus the allowance.
     */
    public function testPacedKnownDeviceIsBoundedLikeAnUnknownDeviceAfterAllowance(): void
    {
        $known = $this->dayStream(true, false, 600);

        $this->setUp();
        $unknown = $this->dayStream(false, false, 600);

        $this->assertSame(144, $known['attempts']);
        $this->assertLessThanOrEqual($unknown['reached'] + 8, $known['reached']);
    }

    /**
     * Rotating fingerprint and user agent every 300 s for 24 h creates a fresh
     * K5 and K2 each time, so neither K5 thresholds nor K2 churn apply. S0 lets
     * all 288 attempts reach the verifier; the fingerprint-independent account
     * allowance bounds it like an unknown device.
     */
    public function testRotatingKnownDeviceIsBoundedLikeAnUnknownDeviceAfterAllowance(): void
    {
        $known = $this->dayStream(true, true, 300);

        $this->setUp();
        $unknown = $this->dayStream(false, true, 300);

        $this->assertSame(288, $known['attempts']);
        $this->assertLessThanOrEqual($unknown['reached'] + 8, $known['reached']);
    }

    /**
     * Host-tampered trust (one trusted session presented against twelve
     * accounts, TMX in #113): every target account gets its own K5 and its own
     * allowance, so the per-account verifier reach is bounded.
     */
    public function testHostTamperedTrustAcrossTwelveAccountsIsBoundedPerAccount(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $device = $this->device('stolen', 'HIGH', true);

        $reachedByAccount = array_fill(0, 12, 0);
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $account = $attempt % 12;
            if ($this->preThenFailure($pipeline, $policy, $this->context("victim{$account}", '203.0.113.20'), $device)) {
                $reachedByAccount[$account]++;
            }
            $this->advance(3);
        }

        foreach ($reachedByAccount as $reached) {
            $this->assertLessThanOrEqual(8, $reached);
        }
        $this->assertLessThan(200, array_sum($reachedByAccount));
    }

    public function testLegitimateOwnerTyposThenSuccessAreNeverDenied(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $device = $this->device('owner-laptop', 'HIGH', true);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertTrue($this->preThenFailure($pipeline, $policy, $this->context('typo-owner'), $device));
            $this->advance(5);
        }

        $check = $pipeline->process($policy, $this->context('typo-owner'), RateLimitCommand::checkOnly('login_protection'), $device);
        $success = $pipeline->process($policy, $this->context('typo-owner'), RateLimitCommand::recordSuccess('login_protection'), $device);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $check->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $success->decision);
    }

    /**
     * Characterization (declared in DEC-021, not changed by this slice): K5 L1
     * decisions are actually-issued SOFT_BLOCKs, so they feed the ACTIVE
     * Anti-Equilibrium gate (DECISION_MATRIX.md §2.5). Four typos, three retries
     * during the soft window, and one more typo after K5 decays give an
     * account-wide K4 L2 hard block that also stops the owner's other device.
     */
    public function testK5SoftDecisionsFeedTheAntiEquilibriumAccountGate(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $laptop = $this->device('owner-laptop', 'HIGH', true);
        $phone = $this->device('owner-phone', 'HIGH', true);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertTrue($this->preThenFailure($pipeline, $policy, $this->context('equilibrium-owner'), $laptop));
        }
        for ($retry = 0; $retry < 3; $retry++) {
            $soft = $pipeline->process($policy, $this->context('equilibrium-owner'), RateLimitCommand::checkOnly('login_protection'), $laptop);
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $soft->decision);
        }

        $this->advance(300);
        $this->assertTrue($this->preThenFailure($pipeline, $policy, $this->context('equilibrium-owner'), $laptop));

        $this->assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k4', 'equilibrium-owner'))?->level);
        $other = $pipeline->process($policy, $this->context('equilibrium-owner'), RateLimitCommand::checkOnly('login_protection'), $phone);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $other->decision);
    }

    /**
     * Custom policies that do not opt into the account allowance keep the S0
     * known-device scoring: K4 is never touched by known-device failures.
     */
    public function testCustomPolicyWithoutAllowanceKeepsKnownDeviceFailuresOffK4(): void
    {
        $pipeline = $this->pipeline();
        $policy = $this->customPolicy(null);
        $device = $this->device('custom-known', 'MEDIUM', false, true);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $pipeline->process($policy, $this->context('custom-compat'), RateLimitCommand::recordFailure('custom_authentication_flow'), $device);
        }

        $this->assertNull($this->store->get($this->key('custom_authentication_flow', 'k4', 'custom-compat')));
    }

    /**
     * The allowance is a fixed-epoch account counter and survives outer
     * key-secret rotation: the ninth known-device failure is scored on K4
     * even when it is the first failure written under the new secret. Each
     * failure uses its own IP, user agent and fingerprint so that K2 churn and
     * K5 thresholds stay out of the measurement.
     */
    public function testAllowanceCarriesAcrossOuterSecretRotation(): void
    {
        $policy = new LoginProtectionPolicy();
        $old = $this->pipeline();
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $old->process($policy, $this->context('rotating-owner', "198.51.100.{$attempt}"), RateLimitCommand::recordFailure('login_protection'), $this->rotatedDevice($attempt));
        }
        $this->assertNull($this->store->get($this->key('login_protection', 'k4', 'rotating-owner')));

        $rotated = $this->pipeline('test_secret', 'new_secret');
        $rotated->process($policy, $this->context('rotating-owner', '198.51.100.8'), RateLimitCommand::recordFailure('login_protection'), $this->rotatedDevice(8));

        $this->assertSame(3, $this->store->get($this->key('login_protection', 'k4', 'rotating-owner', 'new_secret'))?->value);
    }

    /**
     * DEC-015: a backend failure while consuming the account allowance is a
     * typed backend failure for the engine's bounded fallback, never a silent
     * normal-mode allowance, and it leaves no partial K5 or K4 score behind.
     */
    public function testAllowanceBackendFailurePropagatesWithoutPartialScoring(): void
    {
        $failing = new PartialFailureRateLimitStore($this->store, throwOnIncrementBudget: new BackendFailureException('allowance backend down'));
        $pipeline = new EvaluationPipeline(
            $failing,
            $this->correlationStore,
            new BudgetTracker($failing, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock,
            null,
        );

        try {
            $pipeline->process(new LoginProtectionPolicy(), $this->context('outage-owner'), RateLimitCommand::recordFailure('login_protection'), $this->device('outage-fp', 'HIGH', true));
            self::fail('Expected the allowance backend failure to propagate.');
        } catch (BackendFailureException) {
        }

        $this->assertNull($this->store->get($this->key('login_protection', 'k5', 'outage-owner:outage-fp')));
        $this->assertNull($this->store->get($this->key('login_protection', 'k4', 'outage-owner')));
    }

    /**
     * @return array{attempts: int, reached: int}
     */
    /**
     * FixedClock::sleep() is a no-op; time must be advanced explicitly.
     */
    private function advance(int $seconds): void
    {
        $this->clock->setNow($this->clock->now()->modify("+{$seconds} seconds"));
    }

    private function dayStream(bool $known, bool $rotate, int $interval): array
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $attempts = 0;
        $reached = 0;
        for ($elapsed = 0; $elapsed < 86400; $elapsed += $interval) {
            $attempts++;
            $suffix = $rotate ? (string) $attempts : 'stable';
            $device = new DeviceIdentityDTO("day-fp-{$suffix}", $known ? 'HIGH' : 'MEDIUM', $known, false, "agent/{$suffix}");
            $reached += $this->preThenFailure($pipeline, $policy, $this->context('day-target'), $device) ? 1 : 0;
            $this->advance($interval);
        }

        return ['attempts' => $attempts, 'reached' => $reached];
    }

    private function preThenFailure(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
    ): bool {
        $pre = $pipeline->process($policy, $context, RateLimitCommand::checkOnly($policy->getName()), $device);
        if ($pre->decision !== RateLimitResultDTO::DECISION_ALLOW) {
            return false;
        }
        $pipeline->process($policy, $context, RateLimitCommand::recordFailure($policy->getName()), $device);

        return true;
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

    private function customPolicy(?ScoreThresholdsDTO $k5): BlockPolicyInterface
    {
        return new class ($k5) implements BlockPolicyInterface {
            public function __construct(private readonly ?ScoreThresholdsDTO $k5) {}

            public function getName(): string
            {
                return 'custom_authentication_flow';
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(100, 200, 300), k5: $this->k5);
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
        };
    }

    private function context(string $account, string $ip = '198.51.100.40'): RateLimitContextDTO
    {
        return new RateLimitContextDTO($ip, 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint, string $confidence = 'MEDIUM', bool $trusted = false, bool $previouslyVerified = false): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $confidence, $trusted, false, 'chrome/123', $previouslyVerified);
    }

    private function rotatedDevice(int $index): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO("rot-fp-{$index}", 'MEDIUM', false, false, "agent/{$index}", true);
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }
}
