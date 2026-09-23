<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertCount(1, $this->correlationStore->distinctItems($scope48));

        $this->clock->setNow($this->clock->now()->modify('+120 seconds'));
        $this->observeFingerprint($pipeline, $policy, $firstIp, 'first');

        self::assertSame(1, $this->correlationStore->distinctCount($scope48));
        self::assertSame($firstExpiry, $this->correlationStore->distinctExpiresAt($scope48));
        self::assertCount(1, $this->correlationStore->distinctItems($scope48));
    }

    public function testHierarchyActivatesOnlyAtExactTwoFourEightWithCapsTtlAndNoWatchState(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $seedFingerprint = 'hierarchy-seed-fingerprint';

        // One participating /64 is not an active /48 and cannot enter /40.
        $this->observeHierarchySeed($pipeline, $policy, $this->ipv6(0, 0, 1), $seedFingerprint);
        $scope48 = $this->hierarchyKey($policy->getName(), 48, '20010db01000');
        $scope40 = $this->hierarchyKey($policy->getName(), 40, '20010db010');
        $scope32 = $this->hierarchyKey($policy->getName(), 32, '20010db0');
        self::assertSame(1, $this->correlationStore->distinctCount($scope48));
        self::assertSame(0, $this->correlationStore->distinctCount($scope40));
        self::assertSame(0, $this->correlationStore->distinctCount($scope32));

        // Three active /48 members are visible in /40 but do not activate it.
        for ($fortyEightIndex = 0; $fortyEightIndex < 3; $fortyEightIndex++) {
            for ($hostIndex = 1; $hostIndex <= 2; $hostIndex++) {
                $this->observeHierarchySeed(
                    $pipeline,
                    $policy,
                    $this->ipv6(0, $fortyEightIndex, $hostIndex),
                    $seedFingerprint,
                );
            }
        }
        self::assertSame(3, $this->correlationStore->distinctCount($scope40));
        self::assertSame(0, $this->correlationStore->distinctCount($scope32));
        $this->assertNoHierarchyWatch($scope48, $scope40, $scope32);

        // The fourth active /48 activates /40, while /32 still has only one
        // active child and remains inactive.
        for ($hostIndex = 1; $hostIndex <= 2; $hostIndex++) {
            $this->observeHierarchySeed(
                $pipeline,
                $policy,
                $this->ipv6(0, 3, $hostIndex),
                $seedFingerprint,
            );
        }
        self::assertSame(4, $this->correlationStore->distinctCount($scope40));
        self::assertSame(1, $this->correlationStore->distinctCount($scope32));

        // Build seven active /40 children. The eighth is the only activation
        // point for /32; extra children cannot exceed the configured cap.
        for ($fortyIndex = 1; $fortyIndex < 7; $fortyIndex++) {
            $this->activateFortyScope($pipeline, $policy, $fortyIndex, $seedFingerprint);
        }
        self::assertSame(7, $this->correlationStore->distinctCount($scope32));
        $this->assertNoHierarchyWatch($scope48, $scope40, $scope32);

        $this->activateFortyScope($pipeline, $policy, 7, $seedFingerprint);
        self::assertSame(8, $this->correlationStore->distinctCount($scope32));

        // A fifth active /48 under the first /40 and a ninth active /40 under
        // the /32 are rejected by the child caps without growing either set.
        $this->activateFortyEightScope($pipeline, $policy, 0, 4, $seedFingerprint);
        $this->activateFortyScope($pipeline, $policy, 8, $seedFingerprint);
        self::assertSame(4, $this->correlationStore->distinctCount($scope40));
        self::assertSame(8, $this->correlationStore->distinctCount($scope32));

        $scope48Expiry = $this->correlationStore->distinctExpiresAt($scope48);
        $scope40Expiry = $this->correlationStore->distinctExpiresAt($scope40);
        $scope32Expiry = $this->correlationStore->distinctExpiresAt($scope32);
        self::assertSame($this->clock->now()->getTimestamp() + 600, $scope48Expiry);
        self::assertSame($this->clock->now()->getTimestamp() + 600, $scope40Expiry);
        self::assertSame($this->clock->now()->getTimestamp() + 600, $scope32Expiry);

        $this->clock->setNow($this->clock->now()->modify('+120 seconds'));
        $this->observeHierarchySeed($pipeline, $policy, $this->ipv6(0, 0, 1), $seedFingerprint);

        self::assertSame($scope48Expiry, $this->correlationStore->distinctExpiresAt($scope48));
        self::assertSame($scope40Expiry, $this->correlationStore->distinctExpiresAt($scope40));
        self::assertSame($scope32Expiry, $this->correlationStore->distinctExpiresAt($scope32));
        $this->assertNoHierarchyWatch($scope48, $scope40, $scope32);
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

    public function testMacroSprayAtFortyIsolatedAcrossActiveFortyEightsAndSecondWatchEscalates(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $this->activateFortyScope($pipeline, $policy, 0, 'spray-hierarchy-seed');

        $ips = [];
        for ($fortyEightIndex = 0; $fortyEightIndex < 4; $fortyEightIndex++) {
            $ips[] = $this->ipv6(0, $fortyEightIndex, 9 + $fortyEightIndex);
        }

        foreach ($ips as $index => $ip) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', "forty-spray-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $scope40 = $this->hierarchyKey('login_protection', 40, $this->prefix($ips[0], 40));
        self::assertSame(
            4,
            $this->correlationStore->distinctCount($this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40)),
        );
        self::assertNull($this->rateLimitStore->get($scope40));
        self::assertNull($this->rateLimitStore->get($this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40)));
        $this->assertOpaqueMembers(
            $this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40),
            ['forty-spray-0', 'forty-spray-1', 'forty-spray-2', 'forty-spray-3'],
        );
        foreach ($ips as $ip) {
            $scope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($ip, 48));
            self::assertLessThan(
                5,
                $this->correlationStore->distinctCount(
                    $this->adaptiveObservationKey('login_protection', 'spray', 48, $scope48),
                ),
            );
        }

        $watchKey = $this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40) . ':watch';
        self::assertSame(1, $this->correlationStore->watchValue($watchKey));

        $result = $pipeline->process(
            $policy,
            new RateLimitContextDTO($ips[0], 'Mozilla/5.0 Chrome/123', 'forty-spray-0'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $this->correlationStore->watchValue($watchKey));
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $ips[0]))?->level,
        );
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 40)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 32)));
    }

    public function testMacroSprayAtThirtyTwoIsolatedAcrossActiveForties(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            $this->activateFortyScope($pipeline, $policy, $fortyIndex, 'spray-hierarchy-seed');
        }

        $ips = [];
        foreach (range(0, 4) as $fortyIndex) {
            $ips[] = $this->ipv6($fortyIndex, 0, 9 + $fortyIndex);
        }

        $lastResult = null;
        foreach ($ips as $index => $ip) {
            $lastResult = $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', "thirty-two-spray-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
        }

        self::assertInstanceOf(RateLimitResultDTO::class, $lastResult);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $lastResult->decision);
        $scope32 = $this->hierarchyKey('login_protection', 32, $this->prefix($ips[0], 32));
        self::assertSame(
            5,
            $this->correlationStore->distinctCount($this->adaptiveObservationKey('login_protection', 'spray', 32, $scope32)),
        );
        self::assertNull($this->rateLimitStore->get($scope32));
        self::assertNull($this->rateLimitStore->get($this->adaptiveObservationKey('login_protection', 'spray', 32, $scope32)));
        foreach ($ips as $ip) {
            $scope40 = $this->hierarchyKey('login_protection', 40, $this->prefix($ip, 40));
            self::assertLessThan(
                5,
                $this->correlationStore->distinctCount(
                    $this->adaptiveObservationKey('login_protection', 'spray', 40, $scope40),
                ),
            );
        }

        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $ips[4]))?->level,
        );

        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 40)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $ips[0], 32)));
    }

    public function testMacroSprayOuterRotationKeepsThresholdCurrentEnforcementAndBoundedBridge(): void
    {
        $policy = new LoginProtectionPolicy();
        $targetIp = $this->ipv6(0, 0, 3);
        $firstIp = $this->ipv6(0, 0, 1);
        $oldScope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($targetIp, 48), 'old_secret');
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('login_protection', 'k1', $firstIp, 'old_secret'),
            600,
            2,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('login_protection', 'k1', $targetIp, 'old_secret'),
            600,
            2,
        );

        $oldSpray = $this->adaptiveObservationKey(
            'login_protection',
            'spray',
            48,
            $oldScope48,
            'old_secret',
        );
        for ($index = 1; $index <= 4; $index++) {
            $this->correlationStore->addDistinctBounded(
                $oldSpray,
                $this->adaptiveMemberKey('login_protection', 'spray', 48, "old-subject-{$index}", 'old_secret'),
                600,
                5,
            );
        }
        $oldSprayItems = $this->correlationStore->distinctItems($oldSpray);
        $oldSprayExpiry = $this->correlationStore->distinctExpiresAt($oldSpray);

        $result = $this->pipeline('prod', 'old_secret', 'test_secret')->process(
            $policy,
            new RateLimitContextDTO($targetIp, 'Mozilla/5.0 Chrome/123', 'current-spray-subject'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $currentScope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($targetIp, 48));
        $currentSpray = $this->adaptiveObservationKey('login_protection', 'spray', 48, $currentScope48);
        $bridge = $this->adaptiveBridgeKey('login_protection', 'spray', 48, $currentSpray);
        self::assertSame(4, $this->correlationStore->distinctCount($oldSpray));
        self::assertSame($oldSprayItems, $this->correlationStore->distinctItems($oldSpray));
        self::assertSame($oldSprayExpiry, $this->correlationStore->distinctExpiresAt($oldSpray));
        self::assertSame(1, $this->correlationStore->distinctCount($currentSpray));
        self::assertSame(1, $this->correlationStore->distinctCount($bridge));
        self::assertLessThanOrEqual(
            (int) $oldSprayExpiry,
            (int) $this->correlationStore->distinctExpiresAt($bridge),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $targetIp))?->level,
        );
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $targetIp, 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $targetIp, 40)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('login_protection', $targetIp, 32)));
    }

    public function testMacroSprayOuterRotationSecondWatchAndTrustedSessionRemainAdvisory(): void
    {
        $policy = new LoginProtectionPolicy();
        $targetIp = $this->ipv6(0, 0, 3);
        $firstIp = $this->ipv6(0, 0, 1);
        $oldScope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($targetIp, 48), 'old_secret');
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('login_protection', 'k1', $firstIp, 'old_secret'),
            600,
            2,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('login_protection', 'k1', $targetIp, 'old_secret'),
            600,
            2,
        );

        $oldSpray = $this->adaptiveObservationKey(
            'login_protection',
            'spray',
            48,
            $oldScope48,
            'old_secret',
        );
        for ($index = 1; $index <= 3; $index++) {
            $this->correlationStore->addDistinctBounded(
                $oldSpray,
                $this->adaptiveMemberKey('login_protection', 'spray', 48, "old-watch-subject-{$index}", 'old_secret'),
                600,
                5,
            );
        }
        $oldWatch = $oldSpray . ':watch';
        $this->correlationStore->incrementWatchFlag($oldWatch, 1800);
        $oldWatchExpiry = $this->correlationStore->watchExpiresAt($oldWatch);
        $oldSprayExpiry = $this->correlationStore->distinctExpiresAt($oldSpray);

        $result = $this->pipeline('prod', 'old_secret', 'test_secret')->process(
            $policy,
            new RateLimitContextDTO($targetIp, 'Mozilla/5.0 Chrome/123', 'current-watch-subject'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO('trusted-current', 'HIGH', true, false, 'chrome/123'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $currentScope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($targetIp, 48));
        $currentSpray = $this->adaptiveObservationKey('login_protection', 'spray', 48, $currentScope48);
        self::assertSame(1, $this->correlationStore->watchValue($currentSpray . ':watch'));
        self::assertSame(1, $this->correlationStore->watchValue($oldWatch));
        self::assertSame($oldWatchExpiry, $this->correlationStore->watchExpiresAt($oldWatch));
        self::assertSame($oldSprayExpiry, $this->correlationStore->distinctExpiresAt($oldSpray));
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('login_protection', 'k1', $targetIp))?->level,
        );
    }

    public function testFingerprintOnlyRotationDoesNotCreatePreviousHierarchyOrSprayNamespace(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $firstIp = $this->ipv6(0, 0, 1);
        $secondIp = $this->ipv6(0, 0, 2);

        $this->observeHierarchySeed($pipeline, $policy, $firstIp, 'spray-seed-one');
        $this->observeHierarchySeed($pipeline, $policy, $secondIp, 'spray-seed-two');
        $result = $pipeline->process(
            $policy,
            new RateLimitContextDTO($secondIp, 'Mozilla/5.0 Chrome/123', 'fingerprint-only-spray'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO(
                'fingerprint-current',
                'MEDIUM',
                false,
                false,
                'chrome/123',
                false,
                'fingerprint-previous',
            ),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $scope48 = $this->hierarchyKey('login_protection', 48, $this->prefix($secondIp, 48));
        $currentSpray = $this->adaptiveObservationKey('login_protection', 'spray', 48, $scope48);
        self::assertSame(1, $this->correlationStore->distinctCount($currentSpray));
        self::assertNull($this->correlationStore->distinctExpiresAt($this->adaptiveBridgeKey(
            'login_protection',
            'spray',
            48,
            $currentSpray,
        )));
        self::assertNull(
            $this->correlationStore->distinctExpiresAt(
                $this->hierarchyKey('login_protection', 48, $this->prefix($secondIp, 48), 'old_secret'),
            ),
        );
    }

    public function testMacroChurnAtFortyIsolatedAcrossActiveFortyEights(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $this->activateFortyScope($pipeline, $policy, 0, 'churn-hierarchy-seed');

        $ips = [];
        foreach (range(0, 2) as $fortyEightIndex) {
            $ips[] = $this->ipv6(0, $fortyEightIndex, 9 + $fortyEightIndex);
        }

        $lastResult = null;
        foreach ($ips as $index => $ip) {
            $lastResult = $this->observeFingerprint(
                $pipeline,
                $policy,
                $ip,
                "forty-churn-{$index}",
            );
        }

        self::assertInstanceOf(RateLimitResultDTO::class, $lastResult);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $lastResult->decision);
        $scope40 = $this->hierarchyKey('api_heavy_protection', 40, $this->prefix($ips[0], 40));
        self::assertSame(
            3,
            $this->correlationStore->distinctCount($this->adaptiveChurnKey('api_heavy_protection', 40, $scope40, 'chrome/123')),
        );
        self::assertNull($this->rateLimitStore->get($scope40));
        self::assertNull($this->rateLimitStore->get($this->adaptiveChurnKey('api_heavy_protection', 40, $scope40, 'chrome/123')));
        $this->assertOpaqueMembers(
            $this->adaptiveChurnKey('api_heavy_protection', 40, $scope40, 'chrome/123'),
            ['forty-churn-0', 'forty-churn-1', 'forty-churn-2'],
        );
        foreach ($ips as $ip) {
            $scope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($ip, 48));
            self::assertLessThan(
                3,
                $this->correlationStore->distinctCount(
                    $this->adaptiveChurnKey('api_heavy_protection', 48, $scope48, 'chrome/123'),
                ),
            );
        }
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $ips[2]))?->level,
        );
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('api_heavy_protection', $ips[2], 48)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('api_heavy_protection', $ips[2], 40)));
        self::assertNull($this->rateLimitStore->get($this->legacyMacroKey('api_heavy_protection', $ips[2], 32)));
    }

    public function testMacroChurnAtThirtyTwoIsolatedAcrossActiveForties(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            $this->activateFortyScope($pipeline, $policy, $fortyIndex, 'churn-hierarchy-seed');
        }

        $ips = [];
        foreach (range(0, 2) as $fortyIndex) {
            $ips[] = $this->ipv6($fortyIndex, 0, 9 + $fortyIndex);
        }

        $lastResult = null;
        foreach ($ips as $index => $ip) {
            $lastResult = $this->observeFingerprint($pipeline, $policy, $ip, "thirty-two-churn-{$index}");
        }

        self::assertInstanceOf(RateLimitResultDTO::class, $lastResult);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $lastResult->decision);
        $scope32 = $this->hierarchyKey('api_heavy_protection', 32, $this->prefix($ips[0], 32));
        self::assertSame(
            3,
            $this->correlationStore->distinctCount($this->adaptiveChurnKey('api_heavy_protection', 32, $scope32, 'chrome/123')),
        );
        self::assertNull($this->rateLimitStore->get($scope32));
        self::assertNull($this->rateLimitStore->get($this->adaptiveChurnKey('api_heavy_protection', 32, $scope32, 'chrome/123')));
        foreach ($ips as $ip) {
            $scope40 = $this->hierarchyKey('api_heavy_protection', 40, $this->prefix($ip, 40));
            self::assertLessThan(
                3,
                $this->correlationStore->distinctCount(
                    $this->adaptiveChurnKey('api_heavy_protection', 40, $scope40, 'chrome/123'),
                ),
            );
        }
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $ips[2]))?->level,
        );
    }

    public function testMacroChurnOuterRotationKeepsLogicalCountPreviousReadOnlyAndBridgeBounded(): void
    {
        $policy = new ApiHeavyProtectionPolicy();
        $targetIp = $this->ipv6(0, 0, 3);
        $firstIp = $this->ipv6(0, 0, 1);
        $oldScope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($targetIp, 48), 'old_secret');
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $firstIp, 'old_secret'),
            600,
            2,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $targetIp, 'old_secret'),
            600,
            2,
        );

        $oldChurn = $this->adaptiveChurnKey(
            'api_heavy_protection',
            48,
            $oldScope48,
            'chrome/123',
            'old_secret',
        );
        for ($index = 1; $index <= 2; $index++) {
            $this->correlationStore->addDistinctBounded(
                $oldChurn,
                $this->adaptiveMemberKey('api_heavy_protection', 'churn', 48, "old-churn-{$index}", 'old_secret'),
                600,
                3,
            );
        }
        $oldItems = $this->correlationStore->distinctItems($oldChurn);
        $oldExpiry = $this->correlationStore->distinctExpiresAt($oldChurn);

        $result = $this->observeFingerprint(
            $this->pipeline('prod', 'old_secret', 'test_secret'),
            $policy,
            $targetIp,
            'current-churn',
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $currentScope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($targetIp, 48));
        $currentChurn = $this->adaptiveChurnKey('api_heavy_protection', 48, $currentScope48, 'chrome/123');
        $bridge = $this->adaptiveBridgeKey('api_heavy_protection', 'churn', 48, $currentChurn);
        self::assertSame($oldItems, $this->correlationStore->distinctItems($oldChurn));
        self::assertSame($oldExpiry, $this->correlationStore->distinctExpiresAt($oldChurn));
        self::assertSame(1, $this->correlationStore->distinctCount($currentChurn));
        self::assertSame(1, $this->correlationStore->distinctCount($bridge));
        self::assertLessThanOrEqual(
            (int) $oldExpiry,
            (int) $this->correlationStore->distinctExpiresAt($bridge),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $targetIp))?->level,
        );
    }

    public function testMacroChurnFingerprintOnlyRotationAliasesCurrentScopeWithoutPreviousGeneration(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $firstIp = $this->ipv6(0, 0, 1);
        $secondIp = $this->ipv6(0, 0, 2);
        $thirdIp = $this->ipv6(0, 0, 3);
        $fourthIp = $this->ipv6(0, 0, 4);

        $this->observeFingerprint($pipeline, $policy, $firstIp, 'fingerprint-one');
        $this->observeFingerprint($pipeline, $policy, $secondIp, 'fingerprint-two');
        $this->observeFingerprintWithPreviousFingerprint(
            $pipeline,
            $policy,
            $thirdIp,
            'fingerprint-three',
            'fingerprint-not-seen',
        );
        $result = $this->observeFingerprintWithPreviousFingerprint(
            $pipeline,
            $policy,
            $fourthIp,
            'fingerprint-four',
            'fingerprint-three',
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $scope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($fourthIp, 48));
        $currentChurn = $this->adaptiveChurnKey('api_heavy_protection', 48, $scope48, 'chrome/123');
        self::assertSame(2, $this->correlationStore->distinctCount($currentChurn));
        self::assertSame(2, $this->correlationStore->watchValue($currentChurn . ':watch'));
        self::assertNull($this->correlationStore->distinctExpiresAt(
            $this->adaptiveBridgeKey('api_heavy_protection', 'churn', 48, $currentChurn),
        ));
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $fourthIp))?->level,
        );
    }

    public function testMacroChurnBothRotationUsesOnePairAndCarriesSecondWatchThroughBridge(): void
    {
        $policy = new ApiHeavyProtectionPolicy();
        $targetIp = $this->ipv6(0, 0, 3);
        $firstIp = $this->ipv6(0, 0, 1);
        $oldScope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($targetIp, 48), 'old_secret');
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $firstIp, 'old_secret'),
            600,
            2,
        );
        $this->correlationStore->addDistinctBounded(
            $oldScope48,
            $this->enforcementKey('api_heavy_protection', 'k1', $targetIp, 'old_secret'),
            600,
            2,
        );

        $oldChurn = $this->adaptiveChurnKey(
            'api_heavy_protection',
            48,
            $oldScope48,
            'chrome/123',
            'old_secret',
        );
        $this->correlationStore->addDistinctBounded(
            $oldChurn,
            $this->adaptiveMemberKey('api_heavy_protection', 'churn', 48, 'old-other-fingerprint', 'old_secret'),
            600,
            3,
        );
        $this->correlationStore->incrementWatchFlag($oldChurn . ':watch', 1800);
        $oldExpiry = $this->correlationStore->distinctExpiresAt($oldChurn);

        $result = $this->observeFingerprintWithPreviousFingerprint(
            $this->pipeline('prod', 'old_secret', 'test_secret'),
            $policy,
            $targetIp,
            'current-fingerprint',
            'previous-fingerprint',
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $currentScope48 = $this->hierarchyKey('api_heavy_protection', 48, $this->prefix($targetIp, 48));
        $currentChurn = $this->adaptiveChurnKey('api_heavy_protection', 48, $currentScope48, 'chrome/123');
        $bridge = $this->adaptiveBridgeKey('api_heavy_protection', 'churn', 48, $currentChurn);
        self::assertSame(1, $this->correlationStore->watchValue($oldChurn . ':watch'));
        self::assertSame(1, $this->correlationStore->watchValue($currentChurn . ':watch'));
        self::assertSame($oldExpiry, $this->correlationStore->distinctExpiresAt($oldChurn));
        self::assertSame(1, $this->correlationStore->distinctCount($currentChurn));
        self::assertSame(1, $this->correlationStore->distinctCount($bridge));
        self::assertLessThanOrEqual(
            (int) $oldExpiry,
            (int) $this->correlationStore->distinctExpiresAt($bridge),
        );
        self::assertNull($this->correlationStore->distinctExpiresAt(
            $this->adaptiveChurnKey('api_heavy_protection', 48, $currentScope48, 'chrome/123', 'old_secret'),
        ));
        self::assertNull($this->correlationStore->distinctExpiresAt(
            $this->adaptiveChurnKey('api_heavy_protection', 48, $oldScope48, 'chrome/123'),
        ));
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $targetIp))?->level,
        );
    }

    public function testIpv6DilutionKeepsCanonical64MembersAfterAllMacroScopesAreActive(): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $this->seedAllHierarchyScopes('api_heavy_protection');
        $fingerprint = 'ipv6-dilution-low';
        $ips = [];
        $lastResult = null;

        for ($fortyIndex = 0; $fortyIndex < 6; $fortyIndex++) {
            $ip = $this->ipv6($fortyIndex, 0, 9 + $fortyIndex);
            $ips[] = $ip;
            $lastResult = $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
                RateLimitCommand::checkOnly('api_heavy_protection'),
                new DeviceIdentityDTO($fingerprint, 'LOW', false, false, 'chrome/123'),
            );
        }

        self::assertInstanceOf(RateLimitResultDTO::class, $lastResult);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $lastResult->decision);
        $dilutionScope = $this->dilutionKey('api_heavy_protection', $fingerprint);
        self::assertSame(6, $this->correlationStore->distinctCount($dilutionScope));
        $expectedMembers = array_map(
            fn(string $ip): string => $this->dilutionMemberKey(
                'api_heavy_protection',
                $this->enforcementKey('api_heavy_protection', 'k1', $ip),
            ),
            $ips,
        );
        $actualMembers = $this->correlationStore->distinctItems($dilutionScope);
        sort($expectedMembers);
        sort($actualMembers);
        self::assertSame($expectedMembers, $actualMembers);

        foreach ($actualMembers as $member) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $member);
            self::assertStringNotContainsString('2001:0db0', $member);
            self::assertStringNotContainsString('20010db0', $member);
        }

        $scope32 = $this->hierarchyKey('api_heavy_protection', 32, $this->prefix($ips[0], 32));
        $macroChurn = $this->adaptiveChurnKey('api_heavy_protection', 32, $scope32, 'chrome/123');
        foreach ($this->correlationStore->distinctItems($macroChurn) as $member) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $member);
            self::assertStringNotContainsString('20010db0', $member);
        }
        self::assertNull($this->rateLimitStore->checkBlock($this->fingerprintKey('api_heavy_protection', $ips[5], $fingerprint)));
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $ips[5]))?->level,
        );
    }

    #[DataProvider('ipv6DilutionConfidenceLevels')]
    public function testIpv6DilutionMediumAndHighKeepTwoWindowConfirmation(string $confidence): void
    {
        $pipeline = $this->pipeline();
        $policy = new ApiHeavyProtectionPolicy();
        $fingerprint = "ipv6-dilution-{$confidence}";
        $firstWindowId = $this->windowId();

        for ($fortyEightIndex = 0; $fortyEightIndex < 6; $fortyEightIndex++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO($this->ipv6(0, $fortyEightIndex, 9 + $fortyEightIndex), 'Mozilla/5.0 Chrome/123'),
                RateLimitCommand::checkOnly('api_heavy_protection'),
                new DeviceIdentityDTO($fingerprint, $confidence, false, false, 'chrome/123'),
            );
            if ($fortyEightIndex === 5) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, $confidence);
            }
        }

        $dilutionScope = $this->dilutionKey('api_heavy_protection', $fingerprint);
        $firstConfirmation = $this->dilutionConfirmationKey(
            'api_heavy_protection',
            $dilutionScope,
            $firstWindowId,
        );
        $firstConfirmationExpiry = $this->correlationStore->watchExpiresAt($firstConfirmation);
        self::assertSame(1, $this->correlationStore->watchValue($firstConfirmation));

        $this->clock->setNow($this->clock->now()->modify('+600 seconds'));
        $secondWindowId = $this->windowId();
        $lastIp = null;
        for ($fortyEightIndex = 0; $fortyEightIndex < 6; $fortyEightIndex++) {
            $lastIp = $this->ipv6(1, $fortyEightIndex, 9 + $fortyEightIndex);
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO($lastIp, 'Mozilla/5.0 Chrome/123'),
                RateLimitCommand::checkOnly('api_heavy_protection'),
                new DeviceIdentityDTO($fingerprint, $confidence, false, false, 'chrome/123'),
            );
            if ($fortyEightIndex >= 4) {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, $confidence);
            }
        }

        self::assertSame($firstConfirmationExpiry, $this->correlationStore->watchExpiresAt($firstConfirmation));
        self::assertSame(1, $this->correlationStore->watchValue($firstConfirmation));
        self::assertGreaterThanOrEqual(
            1,
            $this->correlationStore->watchValue(
                $this->dilutionConfirmationKey('api_heavy_protection', $dilutionScope, $secondWindowId),
            ),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->fingerprintKey('api_heavy_protection', $lastIp, $fingerprint))?->level,
        );
    }

    public function testIpv6DilutionOuterRotationKeepsPreviousReadOnlyAndCurrentCanonicalMembers(): void
    {
        $policy = new ApiHeavyProtectionPolicy();
        $fingerprint = 'ipv6-dilution-rotation';
        $previousFingerprint = 'ipv6-dilution-previous';
        $oldScope = $this->dilutionKey('api_heavy_protection', $previousFingerprint, 'old_secret');
        $oldIps = [];
        for ($index = 0; $index < 5; $index++) {
            $ip = $this->ipv6(0, $index, 9 + $index);
            $oldIps[] = $ip;
            $this->correlationStore->addDistinctBounded(
                $oldScope,
                $this->enforcementKey('api_heavy_protection', 'k1', $ip, 'old_secret'),
                600,
                6,
            );
        }
        $oldItems = $this->correlationStore->distinctItems($oldScope);
        $oldExpiry = $this->correlationStore->distinctExpiresAt($oldScope);

        $targetIp = $this->ipv6(0, 5, 14);
        $result = $this->pipeline('prod', 'old_secret', 'test_secret')->process(
            $policy,
            new RateLimitContextDTO($targetIp, 'Mozilla/5.0 Chrome/123'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
            new DeviceIdentityDTO(
                $fingerprint,
                'LOW',
                false,
                false,
                'chrome/123',
                false,
                $previousFingerprint,
            ),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame($oldItems, $this->correlationStore->distinctItems($oldScope));
        self::assertSame($oldExpiry, $this->correlationStore->distinctExpiresAt($oldScope));
        $currentScope = $this->dilutionKey('api_heavy_protection', $fingerprint);
        self::assertSame(1, $this->correlationStore->distinctCount($currentScope));
        $currentBridge = $this->correlationBridgeKey('api_heavy_protection', 'dilution', $currentScope);
        self::assertSame(
            1,
            $this->correlationStore->distinctCount($currentBridge),
        );
        self::assertLessThanOrEqual(
            (int) $oldExpiry,
            (int) $this->correlationStore->distinctExpiresAt($currentBridge),
        );
        self::assertSame(
            2,
            $this->rateLimitStore->checkBlock($this->enforcementKey('api_heavy_protection', 'k2', $targetIp))?->level,
        );
    }

    public function testIpv6EphemeralOverflowStillSuppressesDilutionState(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $accountId = 'ipv6-ephemeral-account';
        $ip = $this->ipv6(0, 0, 90);

        for ($index = 1; $index <= 10; $index++) {
            $ua = $index <= 2 ? 'shared-ua' : "ua-{$index}";
            $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, $ua, $accountId),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO("overflow-device-{$index}", 'MEDIUM', false, false, $ua),
            );
        }

        $overflowFingerprint = 'overflow-device-11';
        $result = $pipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'shared-ua', $accountId),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO($overflowFingerprint, 'MEDIUM', false, false, 'shared-ua'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(0, $this->correlationStore->distinctCount($this->dilutionKey('login_protection', $overflowFingerprint)));
        self::assertNull($this->rateLimitStore->checkBlock($this->fingerprintKey('login_protection', $ip, $overflowFingerprint)));
        self::assertNull(
            $this->rateLimitStore->get($this->accountDeviceKey('login_protection', $accountId, $overflowFingerprint)),
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

    public function testMissingBoundedRotationCapabilityFailsBeforeIpv6HierarchyMutation(): void
    {
        $boundedOnlyStore = new BoundedOnlyCorrelationStore();
        $pipeline = new EvaluationPipeline(
            $this->rateLimitStore,
            $boundedOnlyStore,
            new BudgetTracker($this->rateLimitStore, $this->clock),
            new AntiEquilibriumGate($boundedOnlyStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($boundedOnlyStore),
            'test_secret',
            'prod',
            $this->clock,
            'old_secret',
        );

        try {
            $pipeline->process(
                new LoginProtectionPolicy(),
                new RateLimitContextDTO(
                    $this->ipv6(0, 0, 1),
                    'Mozilla/5.0 Chrome/123',
                    'hierarchy-rotation-subject',
                ),
                RateLimitCommand::checkOnly('login_protection'),
                new DeviceIdentityDTO(null, 'LOW', false, false, 'chrome/123'),
            );
            self::fail('The IPv6 hierarchy rotation capability must be required before mutation.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString(
                'Bounded correlation rotation requires the BoundedCorrelationRotationStoreInterface capability.',
                $exception->getMessage(),
            );
        }

        // The bounded base capability is present, but the rotation capability
        // check fails before the hierarchy store receives any mutation call.
        self::assertSame([], $boundedOnlyStore->boundedCalls);
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

    private function observeHierarchySeed(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        string $ip,
        string $fingerprint,
    ): void {
        $pipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123'),
            RateLimitCommand::recordSuccess($policy->getName()),
            new DeviceIdentityDTO($fingerprint, 'MEDIUM', false, false, 'chrome/123'),
        );
    }

    private function activateFortyEightScope(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        int $fortyIndex,
        int $fortyEightIndex,
        string $fingerprint,
    ): void {
        for ($hostIndex = 1; $hostIndex <= 2; $hostIndex++) {
            $this->observeHierarchySeed(
                $pipeline,
                $policy,
                $this->ipv6($fortyIndex, $fortyEightIndex, $hostIndex),
                $fingerprint,
            );
        }
    }

    private function activateFortyScope(
        EvaluationPipeline $pipeline,
        BlockPolicyInterface $policy,
        int $fortyIndex,
        string $fingerprint,
    ): void {
        for ($fortyEightIndex = 0; $fortyEightIndex < 4; $fortyEightIndex++) {
            $this->activateFortyEightScope($pipeline, $policy, $fortyIndex, $fortyEightIndex, $fingerprint);
        }
    }

    private function seedAllHierarchyScopes(string $policy): void
    {
        for ($fortyIndex = 0; $fortyIndex < 8; $fortyIndex++) {
            $scope40 = $this->hierarchyKey($policy, 40, $this->prefix($this->ipv6($fortyIndex, 0, 1), 40));
            for ($fortyEightIndex = 0; $fortyEightIndex < 4; $fortyEightIndex++) {
                $scope48 = $this->hierarchyKey(
                    $policy,
                    48,
                    $this->prefix($this->ipv6($fortyIndex, $fortyEightIndex, 1), 48),
                );
                $this->correlationStore->addDistinctBounded(
                    $scope48,
                    $this->enforcementKey(
                        $policy,
                        'k1',
                        $this->ipv6($fortyIndex, $fortyEightIndex, 1),
                    ),
                    600,
                    2,
                );
                $this->correlationStore->addDistinctBounded(
                    $scope48,
                    $this->enforcementKey(
                        $policy,
                        'k1',
                        $this->ipv6($fortyIndex, $fortyEightIndex, 2),
                    ),
                    600,
                    2,
                );
                $this->correlationStore->addDistinctBounded($scope40, $scope48, 600, 4);
            }

            $scope32 = $this->hierarchyKey($policy, 32, '20010db0');
            $this->correlationStore->addDistinctBounded($scope32, $scope40, 600, 8);
        }
    }

    private function assertNoHierarchyWatch(string ...$scopeKeys): void
    {
        foreach ($scopeKeys as $scopeKey) {
            self::assertSame(0, $this->correlationStore->watchValue($scopeKey . ':watch'));
        }
    }

    /** @param list<string> $rawValues */
    private function assertOpaqueMembers(string $key, array $rawValues): void
    {
        foreach ($this->correlationStore->distinctItems($key) as $member) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $member);
            foreach ($rawValues as $rawValue) {
                self::assertStringNotContainsString($rawValue, $member);
            }
        }
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
    ): RateLimitResultDTO {
        return $pipeline->process(
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

    private function hierarchyBridgeKey(
        string $policy,
        int $cidr,
        string $currentScope,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:hierarchy:v1:{$cidr}:prod:bridge:{$currentScope}",
            $secret,
        );
    }

    private function adaptiveObservationKey(
        string $policy,
        string $purpose,
        int $cidr,
        string $anchor,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:{$purpose}:v1:{$cidr}:prod:scope:{$anchor}",
            $secret,
        );
    }

    private function adaptiveBridgeKey(
        string $policy,
        string $purpose,
        int $cidr,
        string $currentKey,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:{$purpose}:v1:{$cidr}:prod:bridge:{$currentKey}",
            $secret,
        );
    }

    private function correlationBridgeKey(
        string $policy,
        string $purpose,
        string $currentKey,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:{$purpose}:v1:prod:bridge:{$currentKey}",
            $secret,
        );
    }

    private function adaptiveMemberKey(
        string $policy,
        string $purpose,
        int $cidr,
        string $memberSeed,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:{$purpose}:v1:{$cidr}:prod:member:{$memberSeed}",
            $secret,
        );
    }

    private function adaptiveChurnKey(
        string $policy,
        int $cidr,
        string $scope,
        string $ua,
        string $secret = 'test_secret',
    ): string {
        $anchor = hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:ipv6_adaptive:churn_anchor:v1:{$cidr}:prod:{$scope}:{$ua}",
            $secret,
        );

        return $this->adaptiveObservationKey($policy, 'churn', $cidr, $anchor, $secret);
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

    private function fingerprintKey(
        string $policy,
        string $ip,
        string $fingerprint,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:k3:v2:prod:{$this->prefix($ip, 64)}:{$fingerprint}",
            $secret,
        );
    }

    private function accountDeviceKey(string $policy, string $accountId, string $fingerprint, string $secret = 'test_secret'): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:k5:v2:prod:{$accountId}:{$fingerprint}",
            $secret,
        );
    }

    private function dilutionKey(string $policy, string $fingerprint, string $secret = 'test_secret'): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:dilution:v1:prod:scope:{$fingerprint}",
            $secret,
        );
    }

    private function dilutionMemberKey(
        string $policy,
        string $canonicalK1,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:dilution:v1:prod:member:{$canonicalK1}",
            $secret,
        );
    }

    private function dilutionConfirmationKey(
        string $policy,
        string $scope,
        int $windowId,
        string $secret = 'test_secret',
    ): string {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:dilution_confirmation:v1:prod:{$scope}:{$windowId}",
            $secret,
        );
    }

    private function windowId(): int
    {
        return (int) floor($this->clock->now()->getTimestamp() / 600);
    }

    /** @return iterable<string, array{string}> */
    public static function ipv6DilutionConfidenceLevels(): iterable
    {
        yield 'medium' => ['MEDIUM'];
        yield 'high' => ['HIGH'];
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

final class BoundedOnlyCorrelationStore implements BoundedCorrelationStoreInterface
{
    /** @var array<string, int> */
    public array $boundedCalls = [];

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 1;
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->boundedCalls[$key] = ($this->boundedCalls[$key] ?? 0) + 1;

        return new BoundedDistinctResultDTO(1, true);
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
