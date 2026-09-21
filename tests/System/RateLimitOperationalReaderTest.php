<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalKeyStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
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
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class TrackingCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    public int $loadCalls = 0;
    public int $saveCalls = 0;

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        $this->loadCalls++;

        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->saveCalls++;
        $this->states[$policyName] = $state;
    }

    public function seed(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }
}

final class TrackingCorrelationStore implements CorrelationStoreInterface
{
    public int $operations = 0;

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->operations++;

        return 0;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->operations++;

        return 0;
    }

    public function getWatchFlag(string $key): int
    {
        $this->operations++;

        return 0;
    }
}

final class RateLimitOperationalReaderTest extends TestCase
{
    public function testReadExposesEngineStateAndDoesNotMutateRuntimeStores(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $correlationStore = new TrackingCorrelationStore();
        $circuitBreakerStore = new TrackingCircuitBreakerStore();
        $emitter = new RecordingFailureSignalEmitter();
        $engine = $this->createEngine($clock, $store, $correlationStore, $circuitBreakerStore, $emitter);
        $context = new RateLimitContextDTO(
            '203.0.113.10',
            'Mozilla/5.0 Chrome/123',
            'account-123',
            ['platform' => 'web'],
        );

        $engine->limit($context, RateLimitCommand::recordSuccess('api_heavy_protection'));
        $deviceResolver = new DeviceIdentityResolver(new FingerprintHasher('test-secret'));
        $reader = new RateLimitOperationalReader(
            $deviceResolver,
            $store,
            $circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            'test-secret',
            'prod',
        );

        $writesBefore = $store->writeCount();
        $correlationOperationsBefore = $correlationStore->operations;
        $circuitSavesBefore = $circuitBreakerStore->saveCalls;
        $circuitLoadsBefore = $circuitBreakerStore->loadCalls;
        $snapshot = $reader->read($context, new ApiHeavyProtectionPolicy());

        self::assertSame('api_heavy_protection', $snapshot->policyName);
        self::assertSame($clock->now()->getTimestamp(), $snapshot->observedAt);
        self::assertTrue($snapshot->backendHealthy);
        self::assertSame(1, $snapshot->scopes->k1->score?->value);
        self::assertSame(1, $snapshot->scopes->k2->score?->value);
        self::assertSame(1, $snapshot->scopes->k3?->score?->value);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $snapshot->scopes->k4);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $snapshot->scopes->k5);
        self::assertNull($snapshot->scopes->k4->score);
        self::assertNull($snapshot->scopes->k5->score);
        self::assertNull($snapshot->budget);
        self::assertNull($snapshot->circuitBreaker);
        self::assertSame($writesBefore, $store->writeCount());
        self::assertSame($correlationOperationsBefore, $correlationStore->operations);
        self::assertSame($circuitSavesBefore, $circuitBreakerStore->saveCalls);
        self::assertSame($circuitLoadsBefore + 1, $circuitBreakerStore->loadCalls);
    }

    public function testReaderUsesNullableScopesAndIpv6HierarchyWithoutCreatingState(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $circuitBreakerStore = new TrackingCircuitBreakerStore();
        $reader = new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('test-secret')),
            $store,
            $circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            'test-secret',
            'prod',
        );

        $ipv4 = $reader->read(new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0'), new ApiHeavyProtectionPolicy());
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv4->scopes->k1);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv4->scopes->k2);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv4->scopes->k3);
        self::assertNull($ipv4->scopes->k4);
        self::assertNull($ipv4->scopes->k5);
        self::assertNull($ipv4->scopes->k1_48);
        self::assertNull($ipv4->scopes->k1_40);
        self::assertNull($ipv4->scopes->k1_32);

        $ipv6 = $reader->read(
            new RateLimitContextDTO('2001:db8:1234:5678::10', 'Mozilla/5.0', 'account-123', ['platform' => 'web']),
            new ApiHeavyProtectionPolicy(),
        );
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k3);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k4);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k5);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k1_48);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k1_40);
        self::assertInstanceOf(RateLimitOperationalKeyStateDTO::class, $ipv6->scopes->k1_32);
    }

    public function testBudgetOwningPolicyWithoutAccountOmitsAccountScopesAndBudgetWithoutMutation(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $correlationStore = new TrackingCorrelationStore();
        $circuitBreakerStore = new TrackingCircuitBreakerStore();
        $reader = new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('test-secret')),
            $store,
            $circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            'test-secret',
            'prod',
        );
        $context = new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0');
        $writesBefore = $store->writeCount();
        $circuitSavesBefore = $circuitBreakerStore->saveCalls;
        $correlationOperationsBefore = $correlationStore->operations;

        $snapshot = $reader->read($context, new LoginProtectionPolicy());

        self::assertNull($snapshot->scopes->k4);
        self::assertNull($snapshot->scopes->k5);
        self::assertNull($snapshot->budget);
        self::assertSame($writesBefore, $store->writeCount());
        self::assertSame($circuitSavesBefore, $circuitBreakerStore->saveCalls);
        self::assertSame($correlationOperationsBefore, $correlationStore->operations);
    }

    public function testRotationUsesCurrentStateBeforePreviousFallbackWithoutMerging(): void
    {
        $clock = new FixedClock();
        $context = new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0', 'account-123', ['platform' => 'web']);
        $resolver = new DeviceIdentityResolver(
            new FingerprintHasher('current-fingerprint'),
            new FingerprintHasher('previous-fingerprint'),
        );
        $device = $resolver->resolve($context);
        $store = new InMemoryRateLimitStore($clock);
        $circuitBreakerStore = new TrackingCircuitBreakerStore();

        $previousK4 = $this->key('login_protection', 'k4', 'prod', 'account-123', 'previous-outer');
        $currentK4 = $this->key('login_protection', 'k4', 'prod', 'account-123', 'current-outer');
        $store->set($previousK4, 9, 86400);
        $store->set($currentK4, 4, 86400);
        $reader = $this->reader($clock, $store, $resolver, $circuitBreakerStore, 'current-outer', 'previous-outer');
        $currentWins = $reader->read($context, new LoginProtectionPolicy());

        $currentK4State = $currentWins->scopes->k4;
        self::assertNotNull($currentK4State);
        self::assertSame(4, $currentK4State->score?->value);
        self::assertFalse($currentK4State->scoreFromPreviousGeneration);

        $fallbackStore = new InMemoryRateLimitStore($clock);
        $fallbackStore->set($previousK4, 9, 86400);
        $previousK4Block = $previousK4;
        $fallbackStore->block($previousK4Block, 2, 600);
        $fallbackStore->incrementBudget($previousK4, 86400, 20);
        $previousMicro = $this->microCapKey('login_protection', 'account-123', $device->previousFingerprintHash, 'previous-outer');
        $fallbackStore->incrementBudget($previousMicro, 86400, 9);
        $previousCooldown = $this->cooldownKey('login_protection', 'account-123', 'prod', 'previous-outer');
        $fallbackStore->increment($previousCooldown, 3600);
        $fallbackWritesBefore = $fallbackStore->writeCount();

        $fallbackReader = $this->reader(
            $clock,
            $fallbackStore,
            $resolver,
            new TrackingCircuitBreakerStore(),
            'current-outer',
            'previous-outer',
        );
        $fallback = $fallbackReader->read($context, new LoginProtectionPolicy());

        $fallbackK4State = $fallback->scopes->k4;
        self::assertNotNull($fallbackK4State);
        self::assertSame(9, $fallbackK4State->score?->value);
        self::assertTrue($fallbackK4State->scoreFromPreviousGeneration);
        self::assertNotNull($fallbackK4State->activeHardBlock);
        self::assertTrue($fallbackK4State->blockFromPreviousGeneration);
        $fallbackBudget = $fallback->budget;
        self::assertNotNull($fallbackBudget);
        self::assertSame(20, $fallbackBudget->accountBudget?->count);
        self::assertTrue($fallbackBudget->accountBudgetFromPreviousGeneration);
        self::assertTrue($fallbackBudget->accountBudgetActive);
        self::assertSame(9, $fallbackBudget->knownDeviceMicroCap?->count);
        self::assertTrue($fallbackBudget->knownDeviceMicroCapFromPreviousGeneration);
        self::assertTrue($fallbackBudget->knownDeviceMicroCapExceeded);
        self::assertTrue($fallbackBudget->cooldownFromPreviousGeneration);
        self::assertSame(3600, $fallbackBudget->cooldownRemainingSeconds);
        self::assertSame($fallbackWritesBefore, $fallbackStore->writeCount());
    }

    public function testCurrentBudgetWinsAndCircuitBreakerStateIsReadOnly(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $resolver = new DeviceIdentityResolver(new FingerprintHasher('current-fingerprint'));
        $context = new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0', 'account-123', ['platform' => 'web']);
        $currentK4 = $this->key('login_protection', 'k4', 'prod', 'account-123', 'current-outer');
        $previousK4 = $this->key('login_protection', 'k4', 'prod', 'account-123', 'previous-outer');
        $store->incrementBudget($currentK4, 86400, 2);
        $store->incrementBudget($previousK4, 86400, 20);
        $circuitBreakerStore = new TrackingCircuitBreakerStore();
        $state = new CircuitBreakerStateDTO(FailureStateDTO::STATE_OPEN, [100], 100, 100, 0, [100], 700);
        $circuitBreakerStore->seed('login_protection', $state);
        $reader = $this->reader(
            $clock,
            $store,
            $resolver,
            $circuitBreakerStore,
            'current-outer',
            'previous-outer',
        );

        $before = $circuitBreakerStore->load('login_protection');
        $snapshot = $reader->read($context, new LoginProtectionPolicy());
        $after = $circuitBreakerStore->load('login_protection');

        $budget = $snapshot->budget;
        self::assertNotNull($budget);
        self::assertSame(2, $budget->accountBudget?->count);
        self::assertFalse($budget->accountBudgetFromPreviousGeneration);
        self::assertFalse($budget->accountBudgetActive);
        self::assertEquals($state, $snapshot->circuitBreaker);
        self::assertEquals($before, $after);
        self::assertSame(3, $circuitBreakerStore->loadCalls);
        self::assertSame(0, $circuitBreakerStore->saveCalls);
    }

    public function testOperationalJsonDoesNotExposeSecretsFingerprintsOrKeys(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $reader = new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('fingerprint-secret')),
            $store,
            new TrackingCircuitBreakerStore(),
            new DecayCalculator($clock),
            $clock,
            'outer-key-secret',
            'prod',
        );
        $context = new RateLimitContextDTO(
            '203.0.113.10',
            'Mozilla/5.0 Chrome/123',
            'account-123',
            ['platform' => 'web'],
            'session-device-123',
        );
        $snapshot = $reader->read($context, new LoginProtectionPolicy());
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $device = (new DeviceIdentityResolver(new FingerprintHasher('fingerprint-secret')))->resolve($context);

        self::assertStringNotContainsString('outer-key-secret', $json);
        self::assertStringNotContainsString('fingerprint-secret', $json);
        self::assertStringNotContainsString((string) $device->fingerprintHash, $json);
        self::assertStringNotContainsString('session-device-123', $json);
        self::assertStringNotContainsString(
            $this->key('login_protection', 'k4', 'prod', 'account-123', 'outer-key-secret'),
            $json,
        );
    }

    private function createEngine(
        FixedClock $clock,
        InMemoryRateLimitStore $store,
        TrackingCorrelationStore $correlationStore,
        TrackingCircuitBreakerStore $circuitBreakerStore,
        RecordingFailureSignalEmitter $emitter,
    ): RateLimiterEngine {
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            'test-secret',
            'prod',
            $clock,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test-secret')),
            $pipeline,
            new CircuitBreaker($circuitBreakerStore, $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [new ApiHeavyProtectionPolicy(), new LoginProtectionPolicy()],
        );
    }

    private function reader(
        FixedClock $clock,
        InMemoryRateLimitStore $store,
        DeviceIdentityResolver $resolver,
        TrackingCircuitBreakerStore $circuitBreakerStore,
        string $currentSecret,
        ?string $previousSecret,
    ): RateLimitOperationalReader {
        return new RateLimitOperationalReader(
            $resolver,
            $store,
            $circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            $currentSecret,
            'prod',
            $previousSecret,
        );
    }

    private function key(string $policy, string $scope, string $environment, string $value, string $secret): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$scope}:v2:{$environment}:{$value}", $secret);
    }

    private function microCapKey(string $policy, string $accountId, ?string $fingerprint, string $secret): string
    {
        self::assertNotNull($fingerprint);

        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:microcap:k5:v1:{$accountId}:{$fingerprint}",
            $secret,
        );
    }

    private function cooldownKey(string $policy, string $accountId, string $environment, string $secret): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:budget_cooldown:v1:{$environment}:{$accountId}",
            $secret,
        );
    }
}
