<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimiterFullCapabilityBuilderWorkflowTest extends TestCase
{
    public function testOneFullCapabilityStoreRunsAllDefaultPoliciesThroughPublicApi(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $limiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
        ));

        self::assertInstanceOf(RateLimiterInterface::class, $limiter);

        $loginContext = $this->context('aggregate-login', ['device' => 'login']);
        $login = $limiter->limit(
            $loginContext,
            RateLimitCommand::recordFailure('login_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $login->decision);
        self::assertSame(3, $store->get($this->scopeKey(
            'login_protection',
            'k4',
            $loginContext,
            'key-secret',
            'fingerprint-secret',
        ))?->value);

        $otpContext = $this->context('aggregate-otp', ['device' => 'otp']);
        $otp = $limiter->limit(
            $otpContext,
            RateLimitCommand::recordFailure('otp_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $otp->decision);
        self::assertSame(1, $otp->blockLevel);

        $apiContext = $this->context('aggregate-api', ['device' => 'api']);
        $api = $limiter->limit(
            $apiContext,
            new RateLimitCommand('api_heavy_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $api->decision);
        self::assertSame(1, $store->get($this->scopeKey(
            'api_heavy_protection',
            'k1',
            $apiContext,
            'key-secret',
            'fingerprint-secret',
        ))?->value);
    }

    public function testPublicWorkflowUsesBoundedSnapshotRotationAndPreviousReadOnlyState(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $oldLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'old-key',
            'fingerprint-secret',
            'prod',
        ));
        $account = 'aggregate-rotation';

        for ($index = 1; $index <= 3; $index++) {
            $result = $oldLimiter->limit(
                $this->deviceContext($account, $index),
                RateLimitCommand::checkOnly('login_protection'),
            );
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $previousScope = $this->distributedDeviceScope('login_protection', $account, 'old-key');
        $previousItems = $store->correlationStore()->distinctItems($previousScope);
        $previousExpiry = $store->correlationStore()->distinctExpiresAt($previousScope);
        self::assertCount(3, $previousItems);
        self::assertNotNull($previousExpiry);

        $currentLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'new-key',
            'fingerprint-secret',
            'prod',
            'old-key',
        ));
        $result = $currentLimiter->limit(
            $this->deviceContext($account, 4),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame($previousItems, $store->correlationStore()->distinctItems($previousScope));
        self::assertSame($previousExpiry, $store->correlationStore()->distinctExpiresAt($previousScope));
        self::assertGreaterThan(
            0,
            $store->correlationStore()->distinctCount(
                $this->distributedDeviceScope('login_protection', $account, 'new-key'),
            ),
        );
    }

    /**
     * @param string|null $previousOuter
     * @param string|null $previousFingerprint
     */
    #[DataProvider('rotationShapes')]
    public function testFullCapabilityPathCoversEveryRotationShapeThroughPublicApi(
        string $currentOuter,
        string $currentFingerprint,
        ?string $previousOuter,
        ?string $previousFingerprint,
        string $stateOuter,
        string $stateFingerprint,
    ): void {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $account = 'aggregate-rotation-' . $currentOuter . '-' . $currentFingerprint;
        $oldLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            $stateOuter,
            $stateFingerprint,
            'prod',
        ));

        for ($index = 1; $index <= 3; $index++) {
            $result = $oldLimiter->limit(
                $this->deviceContext($account, $index),
                RateLimitCommand::checkOnly('login_protection'),
            );
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $stateScope = $this->distributedDeviceScope('login_protection', $account, $stateOuter);
        $stateItems = $store->correlationStore()->distinctItems($stateScope);
        $stateExpiry = $store->correlationStore()->distinctExpiresAt($stateScope);
        self::assertCount(3, $stateItems);
        self::assertNotNull($stateExpiry);

        $currentLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            $currentOuter,
            $currentFingerprint,
            'prod',
            $previousOuter,
            $previousFingerprint,
        ));
        $result = $currentLimiter->limit(
            $this->deviceContext($account, 4),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);

        $currentScope = $this->distributedDeviceScope('login_protection', $account, $currentOuter);
        $currentItems = $store->correlationStore()->distinctItems($currentScope);
        self::assertCount($currentOuter === $stateOuter ? 4 : 1, $currentItems);
        self::assertSame($stateExpiry, $store->correlationStore()->distinctExpiresAt($stateScope));

        if ($currentOuter !== $stateOuter) {
            self::assertSame($stateItems, $store->correlationStore()->distinctItems($stateScope));
        } else {
            foreach ($stateItems as $stateItem) {
                self::assertContains($stateItem, $currentItems);
            }
        }
    }

    /**
     * @param string|null $previousOuter
     * @param string|null $previousFingerprint
     */
    #[DataProvider('rotationShapes')]
    public function testFullCapabilityPathWritesCurrentAndLeavesPreviousK5ReadOnly(
        string $currentOuter,
        string $currentFingerprint,
        ?string $previousOuter,
        ?string $previousFingerprint,
        string $stateOuter,
        string $stateFingerprint,
    ): void {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $context = $this->knownDeviceContext('aggregate-k5-' . $currentOuter . '-' . $currentFingerprint);
        $previousKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $stateOuter,
            $stateFingerprint,
        );
        $currentKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $currentOuter,
            $currentFingerprint,
        );
        $store->set($previousKey, 2, 86400);

        $limiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            $currentOuter,
            $currentFingerprint,
            'prod',
            $previousOuter,
            $previousFingerprint,
        ));
        $result = $limiter->limit(
            $context,
            RateLimitCommand::recordFailure('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(4, $store->get($currentKey)?->value);
        if ($currentKey !== $previousKey) {
            self::assertSame(2, $store->get($previousKey)?->value);
        }

        if ($currentOuter !== $stateOuter && $currentFingerprint !== $stateFingerprint) {
            self::assertNull($store->get($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $currentOuter,
                $stateFingerprint,
            )));
            self::assertNull($store->get($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $stateOuter,
                $currentFingerprint,
            )));
        }
    }

    public function testPublicWorkflowMigratesBudgetSeedIntoCurrentGeneration(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $context = $this->knownDeviceContext('aggregate-budget');
        $oldLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'old-key',
            'fingerprint-secret',
            'prod',
        ));
        $oldLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $oldBudgetKey = $this->microCapKey($context, 'old-key', 'fingerprint-secret');
        $oldBudget = $store->getBudget($oldBudgetKey);
        self::assertNotNull($oldBudget);
        self::assertSame(1, $oldBudget->count);

        $currentLimiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'new-key',
            'fingerprint-secret',
            'prod',
            'old-key',
        ));
        $result = $currentLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        $unchangedOldBudget = $store->getBudget($oldBudgetKey);
        self::assertNotNull($unchangedOldBudget);
        self::assertSame(1, $unchangedOldBudget->count);
        $currentBudget = $store->getBudget($this->microCapKey($context, 'new-key', 'fingerprint-secret'));
        self::assertNotNull($currentBudget);
        self::assertSame(2, $currentBudget->count);
    }

    public function testPublicWorkflowPersistsHardBlockCycleState(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $limiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
        ));
        $context = $this->context('aggregate-cycle', ['device' => 'cycle']);
        $result = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $limiter->limit(
                $context,
                RateLimitCommand::recordFailure('login_protection'),
            );
        }

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        $k4 = $this->scopeKey(
            'login_protection',
            'k4',
            $context,
            'key-secret',
            'fingerprint-secret',
        );
        self::assertSame(2, $store->checkBlock($k4)?->level);
        self::assertSame(
            0,
            $store->readDecayPauseState($k4, null, $clock->now()->getTimestamp() - 86400, $clock->now()->getTimestamp())
                ->elapsedPausedSeconds,
        );
    }

    public function testPublicWorkflowUsesCircuitBreakerProbeLeaseCapability(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $limiter = $this->fullLimiter($clock, $store, new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
        ));
        $openedAt = $clock->now()->getTimestamp();
        $store->save('api_heavy_protection', new CircuitBreakerStateDTO(
            FailureStateDTO::STATE_OPEN,
            [$openedAt, $openedAt, $openedAt],
            $openedAt,
            $openedAt,
            0,
            [$openedAt],
        ));
        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));

        $result = $limiter->limit(
            $this->context('aggregate-probe', ['device' => 'probe']),
            new RateLimitCommand('api_heavy_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame('DEGRADED_MODE', $result->failureMode);
        self::assertSame(1, $store->circuitBreakerStore()->probeAcquisitionCount());
        self::assertSame(
            FailureStateDTO::STATE_HALF_OPEN,
            $store->load('api_heavy_protection')?->status,
        );
    }

    public function testExistingMultiStoreBuilderConstructorStillRunsPublicWorkflow(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $rateLimitStore = new InMemoryRateLimitStore($clock);
        $correlationStore = new StatefulInMemoryCorrelationStore($clock);
        $circuitBreakerStore = new InMemoryCircuitBreakerStore();
        $limiter = (new RateLimiterBuilder(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $rateLimitStore,
            $correlationStore,
            $circuitBreakerStore,
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->build();
        $context = $this->context('multi-store-api', ['device' => 'api']);

        $result = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertNotNull($rateLimitStore->get($this->scopeKey(
            'api_heavy_protection',
            'k1',
            $context,
            'key-secret',
            'fingerprint-secret',
        )));
    }

    private function fullLimiter(
        FixedClock $clock,
        FullCapabilityInMemoryStore $store,
        RateLimiterConfig $config,
    ): RateLimiterInterface {
        return RateLimiterBuilder::fromFullCapabilityStore(
            $config,
            $store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
    }

    /**
     * @param array<string, mixed> $fingerprint
     */
    private function context(string $accountId, array $fingerprint): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.20',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            $fingerprint,
        );
    }

    private function deviceContext(string $accountId, int $index): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.20',
            "Mozilla/5.0 Chrome/{$index}",
            $accountId,
            ['device' => "device-{$index}"],
        );
    }

    private function knownDeviceContext(string $accountId): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable'],
            null,
            false,
            [],
            true,
        );
    }

    private function scopeKey(
        string $policy,
        string $scope,
        RateLimitContextDTO $context,
        string $outerSecret,
        string $fingerprintSecret,
    ): string {
        $base = "{$policy}:rate_limiter:{$scope}:v2:prod:";
        $fingerprintHash = $this->fingerprintHash($context, $fingerprintSecret);
        $raw = match ($scope) {
            'k1' => $base . $context->ip,
            'k2' => $base . $context->ip . ':' . DeviceIdentityResolver::normalizeUserAgent($context->ua),
            'k3' => $base . $context->ip . ':' . $fingerprintHash,
            'k4' => $base . ($context->accountId ?? ''),
            'k5' => $base . ($context->accountId ?? '') . ':' . $fingerprintHash,
            default => throw new \InvalidArgumentException("Unsupported scope: {$scope}"),
        };

        return hash_hmac('sha256', $raw, $outerSecret);
    }

    private function microCapKey(
        RateLimitContextDTO $context,
        string $outerSecret,
        string $fingerprintSecret,
    ): string {
        return hash_hmac(
            'sha256',
            'login_protection:rate_limiter:microcap:k5:v1:'
                . ($context->accountId ?? '') . ':' . $this->fingerprintHash($context, $fingerprintSecret),
            $outerSecret,
        );
    }

    private function fingerprintHash(RateLimitContextDTO $context, string $secret): string
    {
        $hash = (new DeviceIdentityResolver(new FingerprintHasher($secret)))
            ->resolve($context)
            ->fingerprintHash;
        self::assertNotNull($hash);

        return $hash;
    }

    private function distributedDeviceScope(string $policy, string $account, string $secret): string
    {
        $k4 = hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:k4:v2:prod:{$account}",
            $secret,
        );

        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:distributed_account_devices:v1:prod:scope:{$k4}",
            $secret,
        );
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string, string, string}>
     */
    public static function rotationShapes(): iterable
    {
        yield 'no-rotation' => [
            'stable-outer',
            'stable-fingerprint',
            null,
            null,
            'stable-outer',
            'stable-fingerprint',
        ];
        yield 'outer-only' => [
            'new-outer',
            'stable-fingerprint',
            'old-outer',
            null,
            'old-outer',
            'stable-fingerprint',
        ];
        yield 'fingerprint-only' => [
            'stable-outer',
            'new-fingerprint',
            null,
            'old-fingerprint',
            'stable-outer',
            'old-fingerprint',
        ];
        yield 'both-rotated' => [
            'new-outer',
            'new-fingerprint',
            'old-outer',
            'old-fingerprint',
            'old-outer',
            'old-fingerprint',
        ];
    }
}
