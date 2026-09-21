<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class CorrelationBehaviorTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;
    private EvaluationPipeline $pipeline;
    private OtpProtectionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
        $this->policy = new OtpProtectionPolicy();

        $this->pipeline = new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
    }

    public function testChurnEscalatesAfterDistinctFingerprintsShareTheSameK2Identity(): void
    {
        $context = new RateLimitContextDTO('203.0.113.50', 'Mozilla/5.0');
        $results = [];

        foreach (['fingerprint-a', 'fingerprint-b', 'fingerprint-c'] as $fingerprint) {
            $results[] = $this->pipeline->process(
                $this->policy,
                $context,
                RateLimitCommand::checkOnly('otp_protection'),
                new DeviceIdentityDTO($fingerprint, 'LOW', false, false, 'mozilla/5.0'),
            );
        }

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $results[0]->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $results[1]->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $results[2]->decision);
        $this->assertSame(2, $results[2]->blockLevel);
        $this->assertSame(60, $results[2]->retryAfter);
    }

    public function testDilutionSetsTheNMinusOneWatchFlagAndEscalatesOnTheNextDistinctIp(): void
    {
        $fingerprint = 'dilution-fingerprint';
        $command = RateLimitCommand::checkOnly('otp_protection');

        for ($octet = 1; $octet <= 5; $octet++) {
            $result = $this->pipeline->process(
                $this->policy,
                new RateLimitContextDTO("198.51.100.{$octet}", 'Mozilla/5.0'),
                $command,
                new DeviceIdentityDTO($fingerprint, 'LOW', false, false, 'mozilla/5.0'),
            );

            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $this->assertSame(1, $this->correlationStore->getWatchFlag("watch_dilution:{$fingerprint}"));

        $result = $this->pipeline->process(
            $this->policy,
            new RateLimitContextDTO('198.51.100.6', 'Mozilla/5.0'),
            $command,
            new DeviceIdentityDTO($fingerprint, 'LOW', false, false, 'mozilla/5.0'),
        );

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame(60, $result->retryAfter);
    }

    public function testAntiEquilibriumEscalatesAfterThreeSoftBlockSignals(): void
    {
        $gate = new AntiEquilibriumGate($this->correlationStore);

        $gate->recordSoftBlock('account-123');
        $gate->recordSoftBlock('account-123');
        $this->assertFalse($gate->shouldEscalate('account-123'));

        $gate->recordSoftBlock('account-123');

        $this->assertTrue($gate->shouldEscalate('account-123'));
        $this->assertSame(3, $this->correlationStore->getWatchFlag('gate:soft:account-123'));
    }
}
