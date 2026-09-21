<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class CredentialSprayRotationTest extends TestCase
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

    public function testNoRotationUsesTheUnchangedBaseContract(): void
    {
        $pipeline = $this->createPipeline(new NullCorrelationStore());

        $result = $pipeline->process(
            new LoginProtectionPolicy(),
            new RateLimitContextDTO('198.51.100.80', 'Mozilla/5.0 Chrome/123', 'base-account'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
    }

    public function testRotationWithBaseOnlyStoreFailsBeforeSprayMutation(): void
    {
        $store = new RecordingBaseOnlyCorrelationStore();
        $pipeline = $this->createPipeline($store, 'current-secret', 'previous-secret');

        $this->expectException(RateLimiterException::class);
        try {
            $pipeline->process(
                new LoginProtectionPolicy(),
                new RateLimitContextDTO('198.51.100.81', 'Mozilla/5.0 Chrome/123', 'rotation-account'),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );
        } finally {
            self::assertSame(0, $store->mutationCalls);
        }
    }

    public function testRotationCapabilityFailureUsesLoginFailureSemanticsAtTheEngineBoundary(): void
    {
        $store = new RecordingBaseOnlyCorrelationStore();
        $pipeline = $this->createPipeline($store, 'current-secret', 'previous-secret');
        $emitter = new RecordingFailureSignalEmitter();
        $engine = new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('fingerprint-secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock),
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            [new LoginProtectionPolicy()],
        );

        $result = $engine->limit(
            new RateLimitContextDTO('198.51.100.81', 'Mozilla/5.0 Chrome/123', 'rotation-account'),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame('FAIL_CLOSED', $result->failureMode);
        self::assertSame(0, $store->mutationCalls);
    }

    public function testPreviousSprayHistoryAndBridgeReachThresholdWithoutDoubleCounting(): void
    {
        $policy = new LoginProtectionPolicy();
        $oldPipeline = $this->createPipeline($this->correlationStore, 'previous-secret');
        $ip = '198.51.100.82';

        foreach (['old-1', 'old-2', 'old-3', 'old-4'] as $subject) {
            $oldPipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', $subject),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );
        }

        $previousScope = $this->sprayScope('previous-secret', $ip);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $previousExpiry = $this->correlationStore->distinctExpiresAt($previousScope);
        $previousWatch = $previousScope . ':watch';
        self::assertSame(1, $this->correlationStore->watchValue($previousWatch));

        $currentPipeline = $this->createPipeline($this->correlationStore, 'current-secret', 'previous-secret');
        $result = $currentPipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', 'new-5'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame($previousItems, $this->correlationStore->distinctItems($previousScope));
        self::assertSame($previousExpiry, $this->correlationStore->distinctExpiresAt($previousScope));
        self::assertSame(
            [$this->sprayMember('current-secret', 'new-5')],
            $this->correlationStore->distinctItems($this->bridgeScope('current-secret', $ip)),
        );
        self::assertNotContains('new-5', $this->correlationStore->distinctItems($this->bridgeScope('current-secret', $ip)));
        self::assertNotContains('new-5', $this->correlationStore->distinctItems($previousScope));
        self::assertSame(
            [$this->sprayMember('current-secret', 'new-5')],
            $this->correlationStore->distinctItems($this->sprayScope('current-secret', $ip)),
        );
        self::assertSame(1, $this->correlationStore->watchValue($previousWatch));
    }

    public function testSameLogicalSubjectUsesNoBridgeAndPreviousWatchContinuesAcrossRotation(): void
    {
        $policy = new LoginProtectionPolicy();
        $oldPipeline = $this->createPipeline($this->correlationStore, 'previous-secret');
        $ip = '198.51.100.83';

        foreach (['old-1', 'old-2', 'old-3', 'old-4'] as $subject) {
            $oldPipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', $subject),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );
        }

        $previousScope = $this->sprayScope('previous-secret', $ip);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $currentPipeline = $this->createPipeline($this->correlationStore, 'current-secret', 'previous-secret');
        $result = $currentPipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', 'old-4'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame($previousItems, $this->correlationStore->distinctItems($previousScope));
        self::assertSame([], $this->correlationStore->distinctItems($this->bridgeScope('current-secret', $ip)));
        self::assertSame(1, $this->correlationStore->watchValue($previousScope . ':watch'));
        self::assertSame(1, $this->correlationStore->watchValue($this->sprayScope('current-secret', $ip) . ':watch'));
    }

    public function testExpiredPreviousSprayStateFallsBackToCurrentOnly(): void
    {
        $policy = new LoginProtectionPolicy();
        $oldPipeline = $this->createPipeline($this->correlationStore, 'previous-secret');
        $ip = '198.51.100.84';

        $oldPipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', 'old-subject'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:10:00'));

        $currentPipeline = $this->createPipeline($this->correlationStore, 'current-secret', 'previous-secret');
        $result = $currentPipeline->process(
            $policy,
            new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', 'new-subject'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(
            [$this->sprayMember('current-secret', 'new-subject')],
            $this->correlationStore->distinctItems($this->sprayScope('current-secret', $ip)),
        );
        self::assertSame([], $this->correlationStore->distinctItems($this->bridgeScope('current-secret', $ip)));
    }

    private function createPipeline(
        CorrelationStoreInterface $correlationStore,
        string $secret = 'current-secret',
        ?string $previousSecret = null,
    ): EvaluationPipeline {
        return new EvaluationPipeline(
            $this->rateLimitStore,
            $correlationStore,
            new BudgetTracker($this->rateLimitStore, $this->clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($correlationStore),
            $secret,
            'prod',
            $this->clock,
            $previousSecret,
        );
    }

    private function device(): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO('stable-fp', 'MEDIUM', false, false, 'chrome/123');
    }

    private function sprayScope(string $secret, string $ip): string
    {
        return 'credential_spray:' . $this->k1($secret, $ip);
    }

    private function bridgeScope(string $secret, string $ip): string
    {
        return 'credential_spray:bridge:' . $this->k1($secret, $ip);
    }

    private function sprayMember(string $secret, string $subject): string
    {
        return hash_hmac('sha256', 'credential_spray:subject:v1:' . $subject, $secret);
    }

    private function k1(string $secret, string $ip): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:k1:v2:prod:{$ip}", $secret);
    }
}

final class RecordingBaseOnlyCorrelationStore implements CorrelationStoreInterface
{
    public int $mutationCalls = 0;

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->mutationCalls++;

        return 1;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->mutationCalls++;

        return 1;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}
