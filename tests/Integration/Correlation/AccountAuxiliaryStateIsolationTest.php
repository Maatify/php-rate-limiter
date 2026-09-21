<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
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
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class AccountAuxiliaryStateIsolationTest extends TestCase
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

    public function testMissingFingerprintMarkersArePolicyScopedAndRepeatedWithinThirtyMinutes(): void
    {
        $login = new LoginProtectionPolicy();
        $otp = new OtpProtectionPolicy();
        $pipeline = $this->pipeline();
        $accountId = 'shared-account';
        $device = $this->missingDevice();

        $pipeline->process($login, $this->context($accountId), RateLimitCommand::recordFailure($login->getName()), $device);
        $this->assertSame(3, $this->store->get($this->scoreKey($login->getName(), $accountId))?->value);
        $this->assertSame(
            $this->clock->now()->getTimestamp(),
            $this->store->get($this->auxiliaryKey($login->getName(), 'last_missing_fp', $accountId))?->value,
        );

        $pipeline->process($otp, $this->context($accountId), RateLimitCommand::recordFailure($otp->getName()), $device);
        $this->assertSame(5, $this->store->get($this->scoreKey($otp->getName(), $accountId))?->value);

        $pipeline->process($login, $this->context($accountId), RateLimitCommand::recordFailure($login->getName()), $device);
        $this->assertSame(12, $this->store->get($this->scoreKey($login->getName(), $accountId))?->value);

        $pipeline->process($otp, $this->context($accountId), RateLimitCommand::recordFailure($otp->getName()), $device);
        $this->assertSame(18, $this->store->get($this->scoreKey($otp->getName(), $accountId))?->value);

        $this->assertNull($this->store->get("last_missing_fp:acc:{$accountId}"));
        $this->assertStringNotContainsString($accountId, $this->auxiliaryKey($login->getName(), 'last_missing_fp', $accountId));
        $this->assertNotSame(
            $this->auxiliaryKey($login->getName(), 'last_missing_fp', $accountId),
            $this->auxiliaryKey($otp->getName(), 'last_missing_fp', $accountId),
        );
    }

    public function testMissingFingerprintMarkerOutsideThirtyMinutesDoesNotAddRepeatedDelta(): void
    {
        $policy = new LoginProtectionPolicy();
        $pipeline = $this->pipeline();
        $accountId = 'expired-missing-marker';
        $device = $this->missingDevice();

        $pipeline->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:30:01'));
        $pipeline->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);

        $this->assertSame(3, $this->store->get($this->scoreKey($policy->getName(), $accountId))?->value);
    }

    public function testApiHeavyMissingFingerprintDoesNotReadOrCreateAnAuxiliaryMarker(): void
    {
        $policy = new ApiHeavyProtectionPolicy();
        $accountId = 'api-heavy-account';

        $this->pipeline()->process(
            $policy,
            $this->context($accountId),
            RateLimitCommand::recordFailure($policy->getName()),
            $this->missingDevice(),
        );

        $this->assertNull($this->store->get("last_missing_fp:acc:{$accountId}"));
        $this->assertNull($this->store->get($this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId)));
        $this->assertSame(0, $this->correlationStore->getWatchFlag("gate:soft:{$accountId}"));
        $this->assertSame(0, $this->correlationStore->getWatchFlag("flood_stage:acc:{$accountId}"));
    }

    public function testAuxiliaryMarkersAreSeparatedByEnvironment(): void
    {
        $policy = new LoginProtectionPolicy();
        $accountId = 'environment-scoped-account';
        $device = $this->missingDevice();
        $prod = $this->pipeline(envScope: 'prod');
        $staging = $this->pipeline(envScope: 'staging');

        $prod->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);
        $staging->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);

        $prodKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'test_secret', 'prod');
        $stagingKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'test_secret', 'staging');
        $this->assertNotSame($prodKey, $stagingKey);
        $this->assertNotNull($this->store->get($prodKey));
        $this->assertNotNull($this->store->get($stagingKey));
        $this->assertSame(3, $this->store->get($this->scoreKey($policy->getName(), $accountId, 'test_secret', 'staging'))?->value);
    }

    public function testPreviousMissingMarkerIsReadAfterOuterRotationButNeverWrittenOrRefreshed(): void
    {
        $policy = new LoginProtectionPolicy();
        $accountId = 'rotated-missing-marker';
        $device = $this->missingDevice();
        $old = $this->pipeline(keySecret: 'old-secret');
        $old->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);

        $previousKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'old-secret');
        $previousBefore = $this->store->get($previousKey);
        $previousExpiryBefore = $this->store->expiresAt($previousKey);
        $this->assertNotNull($previousBefore);
        $this->assertNotNull($previousExpiryBefore);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        $current = $this->pipeline(keySecret: 'new-secret', previousSecret: 'old-secret');
        $current->process($policy, $this->context($accountId), RateLimitCommand::recordFailure($policy->getName()), $device);

        $currentKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'new-secret');
        $this->assertSame(12, $this->store->get($this->scoreKey($policy->getName(), $accountId, 'new-secret'))?->value);
        $this->assertSame($this->clock->now()->getTimestamp(), $this->store->get($currentKey)?->value);
        $previousAfter = $this->store->get($previousKey);
        $this->assertNotNull($previousAfter);
        $this->assertSame($previousBefore->value, $previousAfter->value);
        $this->assertSame($previousBefore->updatedAt, $previousAfter->updatedAt);
        $this->assertSame($previousExpiryBefore, $this->store->expiresAt($previousKey));
    }

    public function testCurrentMissingMarkerIsAuthoritativeOverPreviousGeneration(): void
    {
        $policy = new LoginProtectionPolicy();
        $accountId = 'current-marker-authority';
        $currentKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'new-secret');
        $previousKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'old-secret');
        $this->store->set($currentKey, $this->clock->now()->getTimestamp() - 60, 3600);
        $this->store->set($previousKey, $this->clock->now()->getTimestamp() - 2000, 3600);

        $this->pipeline(keySecret: 'new-secret', previousSecret: 'old-secret')->process(
            $policy,
            $this->context($accountId),
            RateLimitCommand::recordFailure($policy->getName()),
            $this->missingDevice(),
        );

        $this->assertSame(9, $this->store->get($this->scoreKey($policy->getName(), $accountId, 'new-secret'))?->value);
    }

    public function testPreviousFingerprintHashAloneDoesNotCreatePreviousAuxiliaryGeneration(): void
    {
        $policy = new LoginProtectionPolicy();
        $accountId = 'fingerprint-only-rotation';
        $oldKey = $this->auxiliaryKey($policy->getName(), 'last_missing_fp', $accountId, 'old-secret');
        $this->store->set($oldKey, $this->clock->now()->getTimestamp(), 3600);

        $device = new DeviceIdentityDTO(null, 'LOW', false, false, 'other/0', false, 'previous-fingerprint-hash');
        $this->pipeline(keySecret: 'new-secret')->process(
            $policy,
            $this->context($accountId),
            RateLimitCommand::recordFailure($policy->getName()),
            $device,
        );

        $this->assertSame(3, $this->store->get($this->scoreKey($policy->getName(), $accountId, 'new-secret'))?->value);
    }

    public function testAntiEquilibriumCombinesDistinctCurrentAndPreviousCountersWithoutDoubleCounting(): void
    {
        $gate = new AntiEquilibriumGate($this->correlationStore);
        $currentKey = $this->auxiliaryKey('login_protection', 'anti_equilibrium', 'rotation-account', 'new-secret');
        $previousKey = $this->auxiliaryKey('login_protection', 'anti_equilibrium', 'rotation-account', 'old-secret');

        $gate->recordSoftBlock($currentKey);
        $gate->recordSoftBlock($currentKey);
        $this->correlationStore->incrementWatchFlag($previousKey, 21600);
        $previousExpiry = $this->correlationStore->watchExpiresAt($previousKey);

        $this->assertTrue($gate->shouldEscalate($currentKey, $previousKey));
        $this->assertSame(2, $this->correlationStore->watchValue($currentKey));
        $this->assertSame(1, $this->correlationStore->watchValue($previousKey));
        $this->assertSame($previousExpiry, $this->correlationStore->watchExpiresAt($previousKey));

        $sameKey = $this->auxiliaryKey('login_protection', 'anti_equilibrium', 'same-key-account', 'same-secret');
        $this->correlationStore->incrementWatchFlag($sameKey, 21600);
        $this->correlationStore->incrementWatchFlag($sameKey, 21600);
        $this->assertFalse($gate->shouldEscalate($sameKey, $sameKey));
    }

    public function testAntiEquilibriumIsPolicyScopedAndThirdSoftRemainsSoft(): void
    {
        $login = $this->softPolicy('login_protection');
        $otp = $this->softPolicy('otp_protection');
        $pipeline = $this->pipeline();
        $accountId = 'policy-scoped-gate';
        $device = $this->missingDevice();

        for ($index = 0; $index < 3; $index++) {
            $result = $pipeline->process($login, $this->context($accountId), RateLimitCommand::recordFailure($login->getName()), $device);
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        }

        for ($index = 0; $index < 3; $index++) {
            $result = $pipeline->process($otp, $this->context($accountId), RateLimitCommand::recordFailure($otp->getName()), $device);
            $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        }

        $this->assertSame(
            3,
            $this->correlationStore->watchValue($this->auxiliaryKey('login_protection', 'anti_equilibrium', $accountId)),
        );
        $this->assertSame(
            3,
            $this->correlationStore->watchValue($this->auxiliaryKey('otp_protection', 'anti_equilibrium', $accountId)),
        );

        $nextLogin = $pipeline->process(
            $login,
            $this->context($accountId),
            RateLimitCommand::recordFailure($login->getName()),
            $device,
        );
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $nextLogin->decision);
        $this->assertSame(2, $nextLogin->blockLevel);
    }

    public function testFloodStageIsPolicyScopedAndContinuesAcrossOuterRotation(): void
    {
        $login = new LoginProtectionPolicy();
        $otp = new OtpProtectionPolicy();
        $accountId = 'flood-stage-account';
        $pipeline = $this->pipeline();

        $loginResults = [];
        for ($index = 1; $index <= 6; $index++) {
            $loginResults[] = $this->floodRequest($pipeline, $login, $accountId, "login-device-{$index}");
        }

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $loginResults[5]->decision);
        $this->assertSame(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            $this->floodRequest($pipeline, $login, $accountId, 'login-device-7')->decision,
        );

        $loginStageKey = $this->auxiliaryKey($login->getName(), 'flood_stage', $accountId);
        $otpStageKey = $this->auxiliaryKey($otp->getName(), 'flood_stage', $accountId);
        $this->assertSame(1, $this->correlationStore->watchValue($loginStageKey));
        $this->assertSame(0, $this->correlationStore->watchValue($otpStageKey));
        $this->assertSame(
            RateLimitResultDTO::DECISION_SOFT_BLOCK,
            $this->floodRequest($pipeline, $otp, $accountId, 'otp-device-1')->decision,
        );
        $this->assertSame(1, $this->correlationStore->watchValue($otpStageKey));
        $this->assertSame(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            $this->floodRequest($pipeline, $otp, $accountId, 'otp-device-2')->decision,
        );

        $previousExpiry = $this->correlationStore->watchExpiresAt($loginStageKey);
        $rotated = $this->pipeline(keySecret: 'new-secret', previousSecret: 'test_secret');
        $rotatedResult = $this->floodRequest($rotated, $login, $accountId, 'login-device-8');
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotatedResult->decision);
        $this->assertSame(0, $this->correlationStore->watchValue($this->auxiliaryKey($login->getName(), 'flood_stage', $accountId, 'new-secret')));
        $this->assertSame(1, $this->correlationStore->watchValue($loginStageKey));
        $this->assertSame($previousExpiry, $this->correlationStore->watchExpiresAt($loginStageKey));
        $this->assertSame(0, $this->correlationStore->getWatchFlag("flood_stage:acc:{$accountId}"));
    }

    private function pipeline(
        string $keySecret = 'test_secret',
        ?string $previousSecret = null,
        string $envScope = 'prod',
    ): EvaluationPipeline {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $keySecret,
            $envScope,
            $this->clock,
            $previousSecret,
        );
    }

    private function context(string $accountId): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.90', 'Mozilla/5.0', $accountId);
    }

    private function missingDevice(): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO(null, 'LOW', false, false, 'other/0');
    }

    private function floodRequest(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        string $accountId,
        string $fingerprint,
    ): RateLimitResultDTO {
        return $pipeline->process(
            $policy,
            $this->context($accountId),
            RateLimitCommand::checkOnly($policy->getName()),
            new DeviceIdentityDTO($fingerprint, 'MEDIUM', false, false, $fingerprint),
        );
    }

    private function scoreKey(
        string $policy,
        string $accountId,
        string $secret = 'test_secret',
        string $env = 'prod',
    ): string {
        return hash_hmac('sha256', "{$policy}:rate_limiter:k4:v2:{$env}:{$accountId}", $secret);
    }

    private function auxiliaryKey(
        string $policy,
        string $purpose,
        string $accountId,
        string $secret = 'test_secret',
        string $env = 'prod',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:aux:{$purpose}:v1:{$env}:{$accountId}",
            $secret,
        );
    }

    private function softPolicy(string $name): BlockPolicyInterface
    {
        return new class ($name) implements BlockPolicyInterface {
            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 100, 200));
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(k4_failure: 1);
            }

            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }

            public function getBudgetConfig(): BudgetConfigDTO
            {
                return new BudgetConfigDTO(100, 1, known_device_micro_cap: null);
            }
        };
    }
}
