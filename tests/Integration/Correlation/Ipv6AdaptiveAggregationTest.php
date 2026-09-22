<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class Ipv6AdaptiveAggregationTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $rateLimitStore;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->rateLimitStore = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testHierarchyUsesExactTwoFourEightActivationCapsAndFixedTtl(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $first48ExpiresAt = null;

        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            for ($fortyEightIndex = 0; $fortyEightIndex < 4; $fortyEightIndex++) {
                for ($hostIndex = 1; $hostIndex <= 2; $hostIndex++) {
                    $ip = $this->ipv6($fortyIndex, $fortyEightIndex, $hostIndex);
                    $pipeline->process(
                        $policy,
                        new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
                        RateLimitCommand::checkOnly('api_heavy_protection'),
                        new DeviceIdentityDTO(
                            "fingerprint-{$fortyIndex}-{$fortyEightIndex}-{$hostIndex}",
                            'MEDIUM',
                            false,
                            false,
                            'chrome/123',
                        ),
                    );

                    $scope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($ip, 48));
                    $first48ExpiresAt ??= $this->correlationStore->distinctExpiresAt($scope48);
                }
            }
        }

        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            $prefix40 = $this->prefix($this->ipv6($fortyIndex, 0, 1), 40);
            $scope40 = $this->hierarchyKey('api_heavy_protection', 40, $prefix40);
            self::assertSame(4, $this->correlationStore->distinctCount($scope40));
            self::assertSame(4, count($this->correlationStore->distinctItems($scope40)));
        }

        $scope32 = $this->hierarchyKey('api_heavy_protection', 32, '20010db0');
        self::assertSame(8, $this->correlationStore->distinctCount($scope32));
        self::assertSame(8, count($this->correlationStore->distinctItems($scope32)));
        self::assertNotNull($first48ExpiresAt);
        self::assertSame($this->clock->now()->getTimestamp() + 600, $first48ExpiresAt);
    }

    public function testSingleAndDuplicate64DoNotActivate48OrRefreshItsTtl(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $firstIp = $this->ipv6(0, 0, 1);
        $scope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($firstIp, 48));

        $this->observeFingerprint($pipeline, $policy, $firstIp, 'first');
        $firstExpiry = $this->correlationStore->distinctExpiresAt($scope48);
        self::assertSame(1, $this->correlationStore->distinctCount($scope48));

        $this->clock->setNow($this->clock->now()->modify('+120 seconds'));
        $this->observeFingerprint($pipeline, $policy, $firstIp, 'first');

        self::assertSame(1, $this->correlationStore->distinctCount($scope48));
        self::assertSame($firstExpiry, $this->correlationStore->distinctExpiresAt($scope48));
        self::assertCount(1, $this->correlationStore->distinctItems($scope48));
    }

    public function testMacroSprayReachesThresholdAcross64sAndPersistsOnlyCurrent64K1(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $ips = [
            $this->ipv6(0, 0, 1),
            $this->ipv6(0, 0, 2),
            $this->ipv6(0, 0, 3),
            $this->ipv6(0, 0, 4),
            $this->ipv6(0, 0, 5),
            $this->ipv6(0, 0, 6),
        ];

        $lastResult = null;
        foreach ($ips as $index => $ip) {
            $lastResult = $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', "spray-subject-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
        }

        self::assertInstanceOf(RateLimitResultDTO::class, $lastResult);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $lastResult->decision);
        $currentK1 = $this->enforcementKey('login_protection', 'k1', $ips[5]);
        self::assertSame(2, $this->rateLimitStore->checkBlock($currentK1)?->level);

        $macroSpray = $this->adaptiveObservationKey(
            'login_protection',
            'spray',
            48,
            $this->hierarchyKey('login_protection', 48, $this->prefix($ips[4], 48)),
        );
        self::assertSame(5, $this->correlationStore->distinctCount($macroSpray));
        self::assertNull($this->rateLimitStore->checkBlock($this->legacyMacroKey('login_protection', $ips[4], 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[4], 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[4], 40)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[4], 32)));
    }

    public function testTrustedSessionTreatsMacroSprayAsAdvisoryButStoresCanonicalK1State(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $ips = [
            $this->ipv6(0, 0, 1),
            $this->ipv6(0, 0, 2),
            $this->ipv6(0, 0, 3),
            $this->ipv6(0, 0, 4),
            $this->ipv6(0, 0, 5),
            $this->ipv6(0, 0, 6),
        ];

        for ($index = 0; $index < 5; $index++) {
            $pipeline->process(
                $policy,
                new RateLimitContextDTO($ips[$index], 'Mozilla/5.0 Chrome/123', "trusted-spray-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
        }

        $result = $pipeline->process(
            $policy,
            new RateLimitContextDTO($ips[5], 'Mozilla/5.0 Chrome/123', 'trusted-spray-5'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO('trusted-fingerprint', 'HIGH', true, false, 'chrome/123'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(2, $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $ips[5]))?->level);
    }

    public function testMacroChurnReachesThresholdAndSecondWatchWithoutMacroK2State(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $firstIp = $this->ipv6(0, 0, 1);
        $secondIp = $this->ipv6(0, 0, 2);

        $this->observeFingerprint($pipeline, $policy, $firstIp, 'churn-1');
        $this->observeFingerprint($pipeline, $policy, $secondIp, 'churn-2');
        $this->observeFingerprint($pipeline, $policy, $secondIp, 'churn-2');
        $this->observeFingerprint($pipeline, $policy, $firstIp, 'churn-3');
        $this->observeFingerprint($pipeline, $policy, $secondIp, 'churn-2');
        $result = $this->observeFingerprint($pipeline, $policy, $secondIp, 'churn-2');

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $secondIp))?->level);
        $macroChurn = $this->adaptiveChurnKey(
            'api_heavy_protection',
            48,
            $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($secondIp, 48)),
            'chrome/123',
        );
        self::assertSame(2, $this->correlationStore->distinctCount($macroChurn));
        self::assertSame(2, $this->correlationStore->watchValue($macroChurn . ':watch'));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('api_heavy_protection', $secondIp, 48)));
    }

    public function testMacroSprayObservesActiveFortyAndThirtyTwoScopes(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $this->primeHierarchy($pipeline, $policy, false);

        $lastIp = null;
        for ($hostIndex = 9; $hostIndex <= 12; $hostIndex++) {
            $lastIp = $this->ipv6(0, 0, $hostIndex);
            $pipeline->process(
                $policy,
                new RateLimitContextDTO($lastIp, 'Mozilla/5.0 Chrome/123', "forty-thirty-two-spray-{$hostIndex}"),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
        }

        $scope40 = $this->hierarchyKey('login_protection', 40, $this->prefix($lastIp, 40));
        $scope32 = $this->hierarchyKey('login_protection', 32, $this->prefix($lastIp, 32));
        self::assertSame(
            5,
            $this->correlationStore->distinctCount($this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40)),
        );
        self::assertSame(
            5,
            $this->correlationStore->distinctCount($this->adaptiveObservationKey('login_protection', 'spray', 32, $scope32)),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $lastIp))?->level,
        );
    }

    public function testMacroChurnObservesActiveFortyAndThirtyTwoScopes(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $this->primeHierarchy($pipeline, $policy, true);

        $lastIp = null;
        for ($hostIndex = 9; $hostIndex <= 11; $hostIndex++) {
            $lastIp = $this->ipv6(0, 0, $hostIndex);
            $this->observeFingerprint($pipeline, $policy, $lastIp, "forty-thirty-two-churn-{$hostIndex}");
        }

        $scope40 = $this->hierarchyKey('api_heavy_protection', 40, $this->prefix($lastIp, 40));
        $scope32 = $this->hierarchyKey('api_heavy_protection', 32, $this->prefix($lastIp, 32));
        self::assertSame(
            3,
            $this->correlationStore->distinctCount($this->adaptiveChurnKey('api_heavy_protection', 40, $scope40, 'chrome/123')),
        );
        self::assertSame(
            3,
            $this->correlationStore->distinctCount($this->adaptiveChurnKey('api_heavy_protection', 32, $scope32, 'chrome/123')),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $lastIp))?->level,
        );
    }

    public function testOuterRotationKeepsHierarchyContinuityPreviousReadOnlyAndBridgeBounded(): void
    {
        $policy = new ApiHeavyProtectionPolicy();
        $targetIp = $this->ipv6(0, 0, 3);
        $firstIp = $this->ipv6(0, 0, 1);
        $secondIp = $this->ipv6(0, 0, 2);

        $oldScope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($targetIp, 48), 'old_secret');
        $oldScope40 = $this->hierarchyKey('api_heavy_protection', 40, $this->prefix($targetIp, 40), 'old_secret');
        $oldScope32 = $this->hierarchyKey('api_heavy_protection', 32, $this->prefix($targetIp, 32), 'old_secret');
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $firstIp, 'old_secret'),
            600,
            2,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $secondIp, 'old_secret'),
            600,
            2,
        );
        $oldScope48Expiry = $this->correlationStore->distinctExpiresAt($oldScope48);
        self::assertSame(2, $this->correlationStore->distinctCount($oldScope48));

        $this->correlationStore->addDistinctBounded(
            $oldScope40,
            $this->hierarchyKey('api_heavy_protection', 48, '20010db01001', 'old_secret'),
            600,
            4,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope40,
            $this->hierarchyKey('api_heavy_protection', 48, '20010db01002', 'old_secret'),
            600,
            4,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope40,
            $this->hierarchyKey('api_heavy_protection', 48, '20010db01003', 'old_secret'),
            600,
            4,
        );
        for ($index = 0; $index < 7; $index++) {
            $this->correlationStore->addDistinctBounded(
                $oldScope32,
                $this->hierarchyKey('api_heavy_protection', 40, sprintf('20010db0%02x', 0x11 + $index), 'old_secret'),
                600,
                8,
            );
        }

        $currentPipeline = $this->pipeline('prod', 'old_secret', 'test_secret');
        $currentResult = $this->observeFingerprint($currentPipeline, $policy, $targetIp, 'current-target');

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $currentResult->decision);
        self::assertSame($oldScope48Expiry, $this->correlationStore->distinctExpiresAt($oldScope48));
        self::assertSame(3, $this->correlationStore->distinctCount($oldScope40));
        self::assertSame(7, $this->correlationStore->distinctCount($oldScope32));

        $currentScope40 = $this->hierarchyKey('api_heavy_protection', 40, $this->prefix($targetIp, 40));
        $currentScope32 = $this->hierarchyKey('api_heavy_protection', 32, $this->prefix($targetIp, 32));
        $currentBridge40 = $this->hierarchyBridgeKey('api_heavy_protection', 40, $currentScope40);
        $currentBridge32 = $this->hierarchyBridgeKey('api_heavy_protection', 32, $currentScope32);
        self::assertNotNull($this->correlationStore->distinctExpiresAt($currentBridge40));
        self::assertNotNull($this->correlationStore->distinctExpiresAt($currentBridge32));
        self::assertLessThanOrEqual(
            (int) $this->correlationStore->distinctExpiresAt($oldScope40),
            (int) $this->correlationStore->distinctExpiresAt($currentBridge40),
        );
        self::assertLessThanOrEqual(
            (int) $this->correlationStore->distinctExpiresAt($oldScope32),
            (int) $this->correlationStore->distinctExpiresAt($currentBridge32),
        );
    }

    public function testFingerprintOnlyRotationDoesNotCreatePreviousHierarchyGeneration(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $firstIp = $this->ipv6(0, 0, 1);
        $secondIp = $this->ipv6(0, 0, 2);
        $thirdIp = $this->ipv6(0, 0, 3);

        $this->observeFingerprint($pipeline, $policy, $firstIp, 'fingerprint-one');
        $this->observeFingerprint($pipeline, $policy, $secondIp, 'fingerprint-two');
        $this->observeFingerprintWithPreviousFingerprint(
            $pipeline,
            $policy,
            $thirdIp,
            'fingerprint-three-current',
            'fingerprint-three-previous',
        );

        $scope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($thirdIp, 48));
        self::assertSame(2, $this->correlationStore->distinctCount($scope48));
        self::assertSame(
            [$scope48],
            array_values(array_filter(
                $this->correlationStore->distinctKeys(),
                static fn(string $key): bool => $key === $scope48,
            )),
        );
    }

    public function testMissingBoundedRotationCapabilityFailsBeforeIpv6HierarchyReset(): void
    {
        $baseOnlyStore = new BaseOnlyCorrelationStore();
        $pipeline = new EvaluationPipeline(
            $this->rateLimitStore,
            $baseOnlyStore,
            new BudgetTracker($this->rateLimitStore, $this->clock),
            new AntiEquilibriumGate($baseOnlyStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($baseOnlyStore),
            'test_secret',
            'prod',
            $this->clock,
            'old_secret',
        );

        $this->expectException(RateLimiterException::class);
        $pipeline->process(
            new ApiHeavyProtectionPolicy(),
            new RateLimitContextDTO($this->ipv6(0, 0, 1), 'Mozilla/5.0 Chrome/123'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
            new DeviceIdentityDTO('current', 'MEDIUM', false, false, 'chrome/123'),
        );
    }

    public function testHierarchyIsPolicyAndEnvironmentIsolatedAndMembersAreOpaque(): void
    {
        $policyIps = [$this->ipv6(0, 0, 1), $this->ipv6(0, 0, 2)];
        foreach ([new LoginProtectionPolicy(), new ApiHeavyProtectionPolicy()] as $policy) {
            $pipeline = $this->pipeline();
            foreach ($policyIps as $index => $ip) {
                $pipeline->process(
                    $policy,
                    new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
                    RateLimitCommand::checkOnly($policy->getName()),
                    new DeviceIdentityDTO("isolated-{$index}", 'MEDIUM', false, false, 'chrome/123'),
                );
            }

            $scope48 = $this->hierarchyKey($policy->getName(), 48, $this->prefix($policyIps[0], 48));
            self::assertSame(2, $this->correlationStore->distinctCount($scope48));
            foreach ($this->correlationStore->distinctItems($scope48) as $member) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $member);
                self::assertStringNotContainsString($this->prefix($policyIps[0], 64), $member);
            }
        }

        $stagingPipeline = $this->pipeline('staging');
        foreach ($policyIps as $index => $ip) {
            $this->observeFingerprint($stagingPipeline, new ApiHeavyProtectionPolicy(), $ip, "staging-{$index}");
        }
        $stagingScope48 = $this->hierarchyKey(
            'api_heavy_protection',
            48,
            $this->prefix($policyIps[0], 48),
            'test_secret',
            'staging',
        );
        self::assertSame(2, $this->correlationStore->distinctCount($stagingScope48));
    }

    public function testSprayAndChurnParticipationMutateHierarchyOncePerRequest(): void
    {
        $countingStore = new CountingCorrelationStore($this->clock);
        $this->correlationStore = $countingStore;
        $pipeline = $this->pipeline();
        $ip = $this->ipv6(0, 0, 1);
        $scope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($ip, 48));

        $pipeline->process(
            new LoginProtectionPolicy(),
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', 'same-subject'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO('same-fingerprint', 'MEDIUM', false, false, 'chrome/123'),
        );

        self::assertSame(1, $countingStore->boundedCalls[$scope48] ?? 0);
    }

    private function pipeline(
        string $environment = 'prod',
        ?string $previousSecret = null,
        string $secret = 'test_secret',
    ): EvaluationPipeline {
        return new EvaluationPipeline(
            $this->rateLimitStore,
            $this->correlationStore,
            new BudgetTracker($this->rateLimitStore, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $secret,
            $environment,
            $this->clock,
            $previousSecret,
        );
    }

    private function observeFingerprint(
        EvaluationPipeline $pipeline,
        ApiHeavyProtectionPolicy $policy,
        string $ip,
        string $fingerprint,
    ): RateLimitResultDTO {
        return $pipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
            new DeviceIdentityDTO($fingerprint, 'MEDIUM', false, false, 'chrome/123'),
        );
    }

    private function observeFingerprintWithPreviousFingerprint(
        EvaluationPipeline $pipeline,
        ApiHeavyProtectionPolicy $policy,
        string $ip,
        string $fingerprint,
        string $previousFingerprint,
    ): void {
        $pipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
            new DeviceIdentityDTO(
                $fingerprint,
                'MEDIUM',
                false,
                false,
                'chrome/123',
                false,
                $previousFingerprint,
            ),
        );
    }

    private function primeHierarchy(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        bool $withFingerprint,
    ): void {
        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            for ($fortyEightIndex = 0; $fortyEightIndex < 4; $fortyEightIndex++) {
                for ($hostIndex = 1; $hostIndex <= 2; $hostIndex++) {
                    $ip = $this->ipv6($fortyIndex, $fortyEightIndex, $hostIndex);
                    $device = $withFingerprint
                        ? new DeviceIdentityDTO(
                            "prime-{$fortyIndex}-{$fortyEightIndex}-{$hostIndex}",
                            'MEDIUM',
                            false,
                            false,
                            'chrome/123',
                        )
                        : new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123');
                    $accountId = $withFingerprint ? null : "prime-subject-{$fortyIndex}-{$fortyEightIndex}-{$hostIndex}";
                    $pipeline->process(
                        $policy,
                        new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', $accountId),
                        RateLimitCommand::checkOnly($policy->getName()),
                        $device,
                    );
                }
            }
        }
    }

    private function ipv6(int $fortyIndex, int $fortyEightIndex, int $hostIndex): string
    {
        $thirdHextet = sprintf('%02x%02x', 0x10 + $fortyIndex, $fortyEightIndex);
        $fourthHextet = sprintf('%04x', $hostIndex);

        return "2001:0db0:{$thirdHextet}:{$fourthHextet}::1";
    }

    private function prefix(string $ip, int $cidr): string
    {
        $packed = inet_pton($ip);
        self::assertNotFalse($packed);

        return substr(bin2hex($packed), 0, (int) ceil($cidr / 4));
    }

    private function hierarchyKey(
        string $policy,
        int $cidr,
        string $prefix,
        string $secret = 'test_secret',
        string $environment = 'prod',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:hierarchy:v1:{$cidr}:{$environment}:{$prefix}",
            $secret,
        );
    }

    private function hierarchyBridgeKey(string $policy, int $cidr, string $currentScope): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:hierarchy:v1:{$cidr}:prod:bridge:{$currentScope}",
            'test_secret',
        );
    }

    private function adaptiveObservationKey(string $policy, string $purpose, int $cidr, string $anchor): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:{$purpose}:v1:{$cidr}:prod:scope:{$anchor}",
            'test_secret',
        );
    }

    private function adaptiveChurnKey(string $policy, int $cidr, string $scope, string $ua): string
    {
        $anchor = hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:churn_anchor:v1:{$cidr}:prod:{$scope}:{$ua}",
            'test_secret',
        );

        return $this->adaptiveObservationKey($policy, 'churn', $cidr, $anchor);
    }

    private function enforcementKey(
        string $policy,
        string $keyType,
        string $ip,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:{$keyType}:v2:prod:{$this->prefix($ip, 64)}" . ($keyType === 'k2' ? ':chrome/123' : ''),
            $secret,
        );
    }

    private function legacyMacroKey(string $policy, string $ip, int $cidr): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:k1:v2:prod:{$this->prefix($ip, $cidr)}",
            'test_secret',
        );
    }
}

final class BaseOnlyCorrelationStore implements CorrelationStoreInterface
{
    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 1;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 1;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}

final class CountingCorrelationStore extends StatefulInMemoryCorrelationStore
{
    /** @var array<string, int> */
    public array $boundedCalls = [];

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO {
        $this->boundedCalls[$key] = ($this->boundedCalls[$key] ?? 0) + 1;

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }
}
