<?php

declare(strict_types=1);

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Host-side adapter that deterministically simulates an unavailable backend.
 */
final class InfrastructureFailureRateLimitStore implements \Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface, \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface, \Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface, \Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface
{
    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO
    {
        $this->fail();
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?\Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): \Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO
    {
        $this->fail();
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO
    {
        $this->fail();
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $this->fail();
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        $this->fail();
    }

    public function replaceScore(string $key, int $value, int $ttlSeconds): ?\Maatify\RateLimiter\DTO\RateLimitStateDTO
    {
        $this->fail();
    }


    private bool $accountLayout = false;
    private ?self $accountView = null;

    /** Example-local isolated in-memory account layout; production Redis shares a transaction through its official view. */
    public function e1Keyspace(): static
    {
        if ($this->accountLayout) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->accountLayout = true;
            $view->accountView = null;
            $this->accountView = $view;
        }
        return $this->accountView;
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        $this->fail();
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): \Maatify\RateLimiter\DTO\DecayPauseStateDTO
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new BackendFailureException('deterministic backend failure');
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->fail();
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $this->fail();
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->fail();
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->fail();
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $this->fail();
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $this->fail();
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->fail();
    }

    public function isHealthy(): bool
    {
        $this->fail();
    }
}

final class InfrastructureFailureCorrelationStore implements \Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface, \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface, \Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface
{
    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $this->fail();
    }


    private bool $accountLayout = false;
    private ?self $accountView = null;

    /** Example-local isolated in-memory account layout; production Redis shares a transaction through its official view. */
    public function e1Keyspace(): static
    {
        if ($this->accountLayout) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->accountLayout = true;
            $view->accountView = null;
            $this->accountView = $view;
        }
        return $this->accountView;
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new BackendFailureException('deterministic backend failure');
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->fail();
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->fail();
    }

    public function getWatchFlag(string $key): int
    {
        $this->fail();
    }
}

final class InfrastructureFailureCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    /** @var array<string, int> */
    private array $probeLeases = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $expiresAt = $this->probeLeases[$policyName] ?? 0;
        if ($expiresAt > $now) {
            return false;
        }

        $this->probeLeases[$policyName] = $now + $leaseSeconds;

        return true;
    }
}

final class InfrastructureFailureSignalEmitter implements FailureSignalEmitterInterface
{
    /** @var list<FailureSignalDTO> */
    public array $signals = [];

    public function emit(FailureSignalDTO $signal): void
    {
        $this->signals[] = $signal;
    }
}

$clock = new SystemClock(new DateTimeZone('UTC'));
$rateLimitStore = new InfrastructureFailureRateLimitStore();
$correlationStore = new InfrastructureFailureCorrelationStore();
$signalEmitter = new InfrastructureFailureSignalEmitter();
$pipeline = new EvaluationPipeline(
    $rateLimitStore,
    $correlationStore,
    new DecayCalculator($clock),
    'example-key-secret',
    'example',
    $clock,
    $clock,
    null,
    null,
);
$limiter = new RateLimiterEngine(
    new DeviceIdentityResolver(new FingerprintHasher('example-fingerprint-secret')),
    $pipeline,
    new CircuitBreaker(new InfrastructureFailureCircuitBreakerStore(), $signalEmitter, $clock),
    new FailureModeResolver(),
    $signalEmitter,
    $clock,
    [new LoginProtectionPolicy()],
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.20',
    ua: 'Mozilla/5.0 Chrome/123',
    accountId: 'account-123',
);

for ($attempt = 1; $attempt <= 3; $attempt++) {
    $result = $limiter->limit($context, RateLimitCommand::checkOnly('login_protection'));
    echo 'attempt=' . $attempt
        . ' decision=' . $result->decision
        . ' failureMode=' . $result->failureMode
        . PHP_EOL;
}

echo 'emittedSignals=' . implode(',', array_map(
    static fn(FailureSignalDTO $signal): string => $signal->type,
    $signalEmitter->signals,
)) . PHP_EOL;
