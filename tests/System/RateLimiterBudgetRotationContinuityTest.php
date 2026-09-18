<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Contract\BlockPolicyInterface;
use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\EphemeralBucket;
use Maatify\RateLimiter\Device\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterBudgetRotationContinuityTest extends TestCase
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

    public function testK4BudgetRotatesWithAtomicSeedMigrationOnFailureUpdate(): void
    {
        $engine = $this->createEngineWithStoreAndSecrets($this->store, 'new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-k4-budget-migration';
        $context = new RateLimitContextDTO(
            '198.51.100.30',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable']
        );
        $oldK4Key = $this->key('login_protection', 'k4', $accountId, 'old_secret');
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');
        $oldBudget = $this->store->incrementBudget($oldK4Key, 86400, 19);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $result->decision);
        $this->assertSame(3, $result->blockLevel);

        $newBudget = $this->store->getBudget($newK4Key);
        $this->assertNotNull($newBudget);
        $this->assertSame(20, $newBudget->count);
        $this->assertSame($oldBudget->epochStart, $newBudget->epochStart);

        $remainingOldBudget = $this->store->getBudget($oldK4Key);
        $this->assertNotNull($remainingOldBudget);
        $this->assertSame(19, $remainingOldBudget->count);
        $this->assertSame($oldBudget->epochStart, $remainingOldBudget->epochStart);
    }

    public function testBudgetV2IsAuthoritativeAndNeverMergedWithV1(): void
    {
        $engine = $this->createEngineWithStoreAndSecrets($this->store, 'new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-budget-v2-authoritative';
        $context = new RateLimitContextDTO(
            '198.51.100.31',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId
        );
        $oldK4Key = $this->key('login_protection', 'k4', $accountId, 'old_secret');
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');
        $newBudget = $this->store->incrementBudget($newK4Key, 86400, 1);
        $this->store->incrementBudget($oldK4Key, 86400, 20);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertSame(0, $result->blockLevel);

        $storedNewBudget = $this->store->getBudget($newK4Key);
        $this->assertNotNull($storedNewBudget);
        $this->assertSame(1, $storedNewBudget->count);
        $this->assertSame($newBudget->epochStart, $storedNewBudget->epochStart);

        $storedOldBudget = $this->store->getBudget($oldK4Key);
        $this->assertNotNull($storedOldBudget);
        $this->assertSame(20, $storedOldBudget->count);
    }

    public function testK5MicroCapRotationSeedsV2FromV1AndKeepsFixedEpoch(): void
    {
        $engine = $this->createEngineWithStoreAndSecrets($this->store, 'new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-microcap-rotation';
        $context = new RateLimitContextDTO(
            '198.51.100.32',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable']
        );
        $device = (new DeviceIdentityResolver(new FingerprintHasher('new_secret')))->resolve($context);
        $this->assertNotNull($device->fingerprintHash);
        $microRaw = "login_protection:rate_limiter:microcap:k5:v1:{$accountId}:{$device->fingerprintHash}";
        $oldMicroKey = hash_hmac('sha256', $microRaw, 'old_secret');
        $newMicroKey = hash_hmac('sha256', $microRaw, 'new_secret');
        $oldMicroBudget = $this->store->incrementBudget($oldMicroKey, 86400, 7);
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        $newMicroBudget = $this->store->getBudget($newMicroKey);
        $this->assertNotNull($newMicroBudget);
        $this->assertSame(8, $newMicroBudget->count);
        $this->assertSame($oldMicroBudget->epochStart, $newMicroBudget->epochStart);

        $storedOldMicroBudget = $this->store->getBudget($oldMicroKey);
        $this->assertNotNull($storedOldMicroBudget);
        $this->assertSame(7, $storedOldMicroBudget->count);
        $this->assertSame($oldMicroBudget->epochStart, $storedOldMicroBudget->epochStart);

        $this->assertSame(1, $this->store->getBudget($newK4Key)?->count);
    }

    public function testMissingBudgetSeedCapabilityFailsExplicitlyWhenMigrationRequired(): void
    {
        $baseOnlyStore = new BaseOnlyInMemoryRateLimitStore($this->clock);
        $engine = $this->createEngineWithStoreAndSecrets($baseOnlyStore, 'new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-base-only-migration';
        $context = new RateLimitContextDTO(
            '198.51.100.33',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable']
        );
        $oldK4Key = $this->key('login_protection', 'k4', $accountId, 'old_secret');
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');
        $oldBudget = $baseOnlyStore->incrementBudget($oldK4Key, 86400, 19);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame('FAIL_CLOSED', $result->failureMode);

        $storedOldBudget = $baseOnlyStore->getBudget($oldK4Key);
        $this->assertNotNull($storedOldBudget);
        $this->assertSame(19, $storedOldBudget->count);
        $this->assertSame($oldBudget->epochStart, $storedOldBudget->epochStart);
        $this->assertNull($baseOnlyStore->getBudget($newK4Key));
    }

    public function testBaseOnlyStoreWithoutV1BudgetContinuesNormalPathWithoutCapability(): void
    {
        $baseOnlyStore = new BaseOnlyInMemoryRateLimitStore($this->clock);
        $engine = $this->createEngineWithStoreAndSecrets($baseOnlyStore, 'new_secret', 'old_secret', new LoginProtectionPolicy());
        $accountId = 'login-base-only-no-migration';
        $context = new RateLimitContextDTO(
            '198.51.100.34',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable']
        );
        $oldK4Key = $this->key('login_protection', 'k4', $accountId, 'old_secret');
        $newK4Key = $this->key('login_protection', 'k4', $accountId, 'new_secret');

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertNull($baseOnlyStore->getBudget($oldK4Key));
        $this->assertSame(1, $baseOnlyStore->getBudget($newK4Key)?->count);
        $this->assertSame(3, $baseOnlyStore->get($newK4Key)?->value);
    }

    private function createEngineWithStoreAndSecrets(
        RateLimitStoreInterface $store,
        string $currentSecret,
        ?string $previousSecret,
        BlockPolicyInterface ...$policies
    ): RateLimiterEngine
    {
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $this->correlationStore,
            new BudgetTracker($store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $currentSecret,
            'prod',
            $this->clock,
            $previousSecret
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher($currentSecret)),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $this->clock),
            new FailureModeResolver(),
            $emitter,
            $this->clock,
            $policies
        );
    }

    private function key(string $policy, string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}",
            $secret
        );
    }
}