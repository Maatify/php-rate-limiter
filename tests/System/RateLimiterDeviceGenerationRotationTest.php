<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObservedCorrelationStore extends StatefulInMemoryCorrelationStore
{
    /** @var list<string> */
    public array $observedItems = [];

    public int $boundedRotationOperations = 0;

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->observedItems[] = $item;

        return parent::addDistinct($key, $item, $ttlSeconds);
    }

    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $this->boundedRotationOperations++;

        return parent::addDistinctBoundedAcrossRotation(
            $currentKey,
            $bridgeKey,
            $previousKey,
            $currentMember,
            $previousMember,
            $ttlSeconds,
            $maxDistinct,
        );
    }
}

final class RateLimiterDeviceGenerationRotationTest extends TestCase
{
    public function testOuterOnlyRotationReadsPreviousGenerationK5ActiveBlock(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('outer-only-account', ['device' => 'stable']);
        $resolver = $this->resolver('stable-fingerprint', null);
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'new-outer',
            'old-outer',
            new LoginProtectionPolicy(),
        );

        $oldKey = $this->deviceKey('login_protection', 'k5', $context, $device->fingerprintHash, 'old-outer');
        $store->block($oldKey, 2, 600);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
    }

    public function testFingerprintOnlyRotationReadsPreviousGenerationK3ActiveBlock(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('fingerprint-only-account', ['device' => 'stable']);
        $resolver = $this->resolver('new-fingerprint', 'old-fingerprint');
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'stable-outer',
            null,
            new ApiHeavyProtectionPolicy(),
        );

        $oldKey = $this->deviceKey('api_heavy_protection', 'k3', $context, $device->previousFingerprintHash, 'stable-outer');
        $store->block($oldKey, 2, 600);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
    }

    public function testBothRotatedReadsPreviousGenerationK5ActiveBlockWithoutCrossPairing(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('both-rotated-account', ['device' => 'stable']);
        $resolver = $this->resolver('new-fingerprint', 'old-fingerprint');
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'new-outer',
            'old-outer',
            new LoginProtectionPolicy(),
        );

        $oldKey = $this->deviceKey('login_protection', 'k5', $context, $device->previousFingerprintHash, 'old-outer');
        $store->block($oldKey, 2, 600);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
    }

    public function testNoRotationKeepsCurrentK5ScoreWriteBehavior(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('no-rotation-account', ['device' => 'stable']);
        $resolver = $this->resolver('stable-fingerprint', null);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'stable-outer',
            null,
            new LoginProtectionPolicy(),
        );
        $device = $resolver->resolve($context);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertSame(
            2,
            $store->get($this->deviceKey('login_protection', 'k5', $context, $device->fingerprintHash, 'stable-outer'))?->value,
        );
    }

    public function testFingerprintOnlyRotationFallsBackToPreviousK3Score(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('k3-score-account', ['device' => 'stable']);
        $resolver = $this->resolver('new-fingerprint', 'old-fingerprint');
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'stable-outer',
            null,
            new ApiHeavyProtectionPolicy(['k3' => 2]),
        );
        $oldKey = $this->deviceKey('api_heavy_protection', 'k3', $context, $device->previousFingerprintHash, 'stable-outer');
        $store->set($oldKey, 2, 86400);

        $result = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertGreaterThanOrEqual(2, $result->blockLevel);
        $this->assertNull($store->get($this->deviceKey('api_heavy_protection', 'k3', $context, $device->fingerprintHash, 'stable-outer')));
    }

    public function testBothRotatedPreviousK5ScoreIsReadButOnlyCurrentK5IsUpdated(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('k5-score-account', ['device' => 'stable']);
        $resolver = $this->resolver('new-fingerprint', 'old-fingerprint');
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'new-outer',
            'old-outer',
            new LoginProtectionPolicy(),
        );
        $oldKey = $this->deviceKey('login_protection', 'k5', $context, $device->previousFingerprintHash, 'old-outer');
        $currentKey = $this->deviceKey('login_protection', 'k5', $context, $device->fingerprintHash, 'new-outer');
        $store->set($oldKey, 2, 86400);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertSame(2, $store->get($oldKey)?->value);
        $this->assertSame(4, $store->get($currentKey)?->value);
    }

    /**
     * @param string|null $previousOuter
     * @param string|null $previousFingerprint
     */
    #[DataProvider('rotationShapes')]
    public function testK5MicroCapMigratesThePreviousEpochAtomicallyAcrossRotationShapes(
        string $currentOuter,
        ?string $previousOuter,
        string $currentFingerprint,
        ?string $previousFingerprint,
    ): void {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('microcap-migration-account', ['device' => 'stable']);
        $resolver = $this->resolver($currentFingerprint, $previousFingerprint);
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            $currentOuter,
            $previousOuter,
            new LoginProtectionPolicy(),
        );

        $previousMicroKey = $this->microKey(
            'login_protection',
            $context->accountId,
            $device->previousFingerprintHash ?? $device->fingerprintHash,
            $previousOuter ?? $currentOuter,
        );
        $previousState = $store->incrementBudget($previousMicroKey, 86400, 7);
        $currentMicroKey = $this->microKey(
            'login_protection',
            $context->accountId,
            $device->fingerprintHash,
            $currentOuter,
        );

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $currentState = $store->getBudget($currentMicroKey);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $this->assertNotNull($currentState);
        $this->assertSame(8, $currentState->count);
        $this->assertSame($previousState->epochStart, $currentState->epochStart);
        $this->assertSame(7, $store->getBudget($previousMicroKey)?->count);
    }

    /**
     * @return iterable<string, array{string, ?string, string, ?string}>
     */
    public static function rotationShapes(): iterable
    {
        yield 'outer-only' => ['new-outer', 'old-outer', 'stable-fingerprint', null];
        yield 'fingerprint-only' => ['stable-outer', null, 'new-fingerprint', 'old-fingerprint'];
        yield 'both' => ['new-outer', 'old-outer', 'new-fingerprint', 'old-fingerprint'];
    }

    public function testCurrentK5MicroCapIsAuthoritativeAndPreviousIsIgnored(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->context('microcap-authoritative-account', ['device' => 'stable']);
        $resolver = $this->resolver('stable-fingerprint', null);
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'new-outer',
            'old-outer',
            new LoginProtectionPolicy(),
        );
        $currentMicroKey = $this->microKey('login_protection', $context->accountId, $device->fingerprintHash, 'new-outer');
        $previousMicroKey = $this->microKey('login_protection', $context->accountId, $device->fingerprintHash, 'old-outer');
        $store->incrementBudget($currentMicroKey, 86400, 3);
        $store->incrementBudget($previousMicroKey, 86400, 100);

        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(4, $store->getBudget($currentMicroKey)?->count);
        $this->assertSame(100, $store->getBudget($previousMicroKey)?->count);
    }

    public function testK5MicroCapMigrationWithoutCapabilityFailsClosedThroughEngine(): void
    {
        $clock = new FixedClock();
        $store = new BaseOnlyInMemoryRateLimitStore($clock);
        $context = $this->context('microcap-capability-account', ['device' => 'stable']);
        $resolver = $this->resolver('stable-fingerprint', null);
        $device = $resolver->resolve($context);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'new-outer',
            'old-outer',
            new LoginProtectionPolicy(),
        );
        $previousMicroKey = $this->microKey('login_protection', $context->accountId, $device->fingerprintHash, 'old-outer');
        $currentMicroKey = $this->microKey('login_protection', $context->accountId, $device->fingerprintHash, 'new-outer');
        $store->incrementBudget($previousMicroKey, 86400, 7);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame('FAIL_CLOSED', $result->failureMode);
        $this->assertSame(7, $store->getBudget($previousMicroKey)?->count);
        $this->assertNull($store->getBudget($currentMicroKey));
    }

    public function testPreviousFingerprintUsesBoundedRotationWithoutCreatingRawPreviousMembers(): void
    {
        [$withoutPrevious, $withoutHashes, $withoutCorrelation] = $this->runEphemeralSequence(null);
        [$withPrevious, $withHashes, $withCorrelation] = $this->runEphemeralSequence('old-fingerprint');

        $this->assertSame($withoutPrevious, $withPrevious);
        $this->assertCount(51, $withHashes);
        $this->assertNotNull($withHashes[0]);
        $this->assertSame(0, $withoutCorrelation->boundedRotationOperations);
        $this->assertGreaterThan(0, $withCorrelation->boundedRotationOperations);
        foreach ($withHashes as $previousHash) {
            $this->assertNotContains($previousHash, $withCorrelation->observedItems);
        }
        $this->assertCount(count($withoutCorrelation->observedItems), $withCorrelation->observedItems);
    }

    /**
     * @return array{list<string>, list<?string>, ObservedCorrelationStore}
     */
    private function runEphemeralSequence(?string $previousFingerprint): array
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $correlation = new ObservedCorrelationStore($clock);
        $resolver = $this->resolver('current-fingerprint', $previousFingerprint);
        $engine = $this->createEngine(
            $store,
            $resolver,
            'stable-outer',
            null,
            new ApiHeavyProtectionPolicy(),
            $correlation,
        );
        $decisions = [];
        $previousHashes = [];

        for ($i = 0; $i < 51; $i++) {
            $context = new RateLimitContextDTO(
                '203.0.113.50',
                "Mozilla/5.0 Chrome/{$i}.0.0.0",
                null,
                ['device' => $i],
            );
            $device = $resolver->resolve($context);
            $previousHashes[] = $device->previousFingerprintHash;
            $decisions[] = $engine->limit($context, RateLimitCommand::checkOnly('api_heavy_protection'))->decision;
        }

        return [$decisions, $previousHashes, $correlation];
    }

    private function createEngine(
        RateLimitStoreInterface $store,
        DeviceIdentityResolver $resolver,
        string $currentOuter,
        ?string $previousOuter,
        BlockPolicyInterface $policy,
        ?CorrelationStoreInterface $correlationStore = null,
    ): RateLimiterEngine {
        $clock = new FixedClock();
        $correlationStore ??= new StatefulInMemoryCorrelationStore($clock);
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            $currentOuter,
            'prod',
            $clock,
            $previousOuter,
        );

        return new RateLimiterEngine(
            $resolver,
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [$policy],
        );
    }

    private function resolver(string $currentFingerprint, ?string $previousFingerprint): DeviceIdentityResolver
    {
        return new DeviceIdentityResolver(
            new FingerprintHasher($currentFingerprint),
            $previousFingerprint !== null ? new FingerprintHasher($previousFingerprint) : null,
        );
    }

    /**
     * @param array<string, mixed> $fingerprint
     */
    private function context(string $accountId, array $fingerprint): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            $fingerprint,
            null,
            false,
            [],
            true,
        );
    }

    private function deviceKey(
        string $policy,
        string $type,
        RateLimitContextDTO $context,
        ?string $fingerprint,
        string $outer,
    ): string {
        $base = "{$policy}:rate_limiter:{$type}:v2:prod:";
        $normalizedUa = DeviceIdentityResolver::normalizeUserAgent($context->ua);
        $raw = match ($type) {
            'k3' => $base . $context->ip . ':' . $fingerprint,
            'k5' => $base . $context->accountId . ':' . $fingerprint,
            'k4' => $base . $context->accountId,
            'k2' => $base . $context->ip . ':' . $normalizedUa,
            default => $base . $context->ip,
        };

        return hash_hmac('sha256', $raw, $outer);
    }

    private function microKey(string $policy, ?string $accountId, ?string $fingerprint, string $outer): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:microcap:k5:v1:{$accountId}:{$fingerprint}",
            $outer,
        );
    }
}
