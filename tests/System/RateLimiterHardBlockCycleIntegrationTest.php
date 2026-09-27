<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterHardBlockCycleIntegrationTest extends TestCase
{
    public function testMissingCapabilityFailsBeforeAnyL2BlockWriteButL1UsesBaseContract(): void
    {
        $clock = new FixedClock('@1000');
        $store = new BaseOnlyInMemoryRateLimitStore($clock);
        $pipeline = new EvaluationPipeline(
            $store,
            new NullCorrelationStore(),
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate(new NullCorrelationStore()),
            new DecayCalculator($clock),
            new EphemeralBucket(new NullCorrelationStore()),
            'test-secret',
            'prod',
            $clock,
        );
        $policy = new class extends ApiHeavyProtectionPolicy {
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(
                    k1: new ScoreThresholdsDTO(1, 2, 3),
                    k2: new ScoreThresholdsDTO(100, 100, 100),
                    k3: new ScoreThresholdsDTO(100, 100, 100),
                );
            }
        };
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla/5.0');
        $device = new DeviceIdentityDTO(null, 'LOW', false, false, 'mozilla:5');

        $first = $pipeline->process($policy, $context, new RateLimitCommand('api_heavy_protection'), $device);
        self::assertSame(1, $first->blockLevel);

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('HardBlockCycleStoreInterface capability');
        $pipeline->process($policy, $context, new RateLimitCommand('api_heavy_protection'), $device);
    }
}
