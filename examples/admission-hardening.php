<?php

declare(strict_types=1);

/**
 * Runnable standalone example for Admission-Reservation policies (DEC-048, DEC-049, DEC-050).
 *
 * Demonstrates the unified public library setup without manual framing, HMAC, or Redis key layout:
 *   1. Loading a stable random >= 32-byte `admissionStateSecret` from outside source code.
 *   2. Building both the Host credential issuer (`AdmissionCredentialCodec::fromConfig($config)`)
 *      and the runtime (`RateLimiterBuilder`) from one `RateLimiterConfig`.
 *   3. Opening the fleet fence, running `checkOnly` + outcome with the exact same context and permit.
 *   4. Issuing a v2 credential after trusted authentication success using `$runtime->bindingEpoch()`.
 *   5. Revoking an account's credentials (`revokeAccountBinding`) and re-issuing to an eligible device.
 *   6. Performing independent routine rotations of `keySecret` (outer) and `bindingKeySecret` without
 *      reviving revoked credentials (revocation survives >= 2 outer rotations).
 *   7. Re-establishing a fresh isolated trust domain and new `admissionStateSecret` if persistent
 *      store continuity is ever lost (never reusing the prior state secret).
 *
 * Uses only the production PSR-4 autoloader and public contracts. Replace the in-memory example
 * store with `RedisFullCapabilityStore` (or another real atomic store) in production.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionReservationStoreInterface;
use Maatify\RateLimiter\Repository\AtomicAdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStateCasStoreInterface;
use Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface;
use Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface;
use Maatify\RateLimiter\Repository\SharedAdmissionConfigStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final class AdmissionExampleClock implements ClockInterface
{
    private DateTimeImmutable $current;

    public function __construct()
    {
        $this->current = new DateTimeImmutable('2026-11-26T00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->current->getTimezone();
    }
}

/**
 * Minimal in-memory adapter implementing the public contracts for this standalone example.
 * In production, pass `RedisFullCapabilityStore` to `RateLimiterBuilder::fromFullCapabilityStore()`.
 */
final class AdmissionExampleStore implements
    PunishmentLifecycleStoreInterface,
    SnapshotFencedScoreStoreInterface,
    ScoreReplaceStoreInterface,
    OperationalReadOnlyStoreInterface,
    BudgetSeedStoreInterface,
    E1KeyspaceStoreInterface,
    AdmissionReservationStoreInterface,
    AtomicAdmissionPermitStoreInterface,
    SharedAdmissionConfigStoreInterface
{
    private bool $e1View = false;
    private ?self $accountView = null;

    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $scores = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    /** @var array<string, int> */
    private array $fences = [];

    /** @var array<string, int> */
    private array $epochs = [];

    /** @var array<string, string> */
    private array $configs = [];

    /** @var array<string, array<string, int>> */
    private array $counters = [];

    /** @var array<string, array<string, int>> */
    private array $sets = [];

    /** @var array<string, array{credit: int, last: int}> */
    private array $buckets = [];

    /** @var array<string, array{state: string, lane: string, subject: string, operation: string, deadline: int, grace: int, outcome: ?string}> */
    private array $permits = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function e1Keyspace(): static
    {
        if ($this->e1View) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->e1View = true;
            $view->accountView = null;
            $this->accountView = $view;
        }

        return $this->accountView;
    }

    public function readAdmissionFence(string $key, ?string $previousKey): int
    {
        if (isset($this->fences[$key])) {
            return $this->fences[$key];
        }

        return $previousKey !== null ? ($this->fences[$previousKey] ?? 0) : 0;
    }

    public function bumpAdmissionFence(string $key, ?string $previousKey): int
    {
        $next = max($this->fences[$key] ?? 0, $previousKey !== null ? ($this->fences[$previousKey] ?? 0) : 0) + 1;
        $this->fences[$key] = $next;

        return $next;
    }

    public function readBindingEpoch(string $key): int
    {
        return $this->epochs[$key] ?? 0;
    }

    public function bumpBindingEpoch(string $key): int
    {
        $next = ($this->epochs[$key] ?? 0) + 1;
        $this->epochs[$key] = $next;

        return $next;
    }

    public function rebindAdmissionConfig(string $verifierClass, AdmissionSharedConfigDTO $config): void
    {
        $this->configs[$verifierClass . ':' . $config->key] = $config->value;
    }

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        if ($request->sharedConfig !== null) {
            $cfgKey = $request->verifierClass . ':' . $request->sharedConfig->key;
            if (isset($this->configs[$cfgKey]) && $this->configs[$cfgKey] !== $request->sharedConfig->value) {
                throw new AdmissionConfigurationException('Admission shared configuration mismatch.');
            }
        }

        foreach ($request->scopes as $scope) {
            $k = $request->verifierClass . ':' . $scope->kind->value . ':' . $scope->key;
            $prevK = $scope->previousKey !== null ? $request->verifierClass . ':' . $scope->kind->value . ':' . $scope->previousKey : null;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $count = $this->liveCount($this->counters[$k] ?? [], $now)
                    + ($prevK !== null ? $this->liveCount($this->counters[$prevK] ?? [], $now) : 0);
                if ($count >= $scope->capacity) {
                    return new AdmissionReservationResultDTO(false, $scope->name, $scope->ttlSeconds);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::SET_SIZE) {
                $live = $this->liveCount($this->sets[$k] ?? [], $now);
                $member = $scope->member ?? '';
                if ($live >= $scope->capacity && ! (($this->sets[$k][$member] ?? 0) > $now)) {
                    return new AdmissionReservationResultDTO(false, $scope->name, $scope->ttlSeconds);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                $live = $this->liveCount($this->sets[$k] ?? [], $now);
                $member = $scope->member ?? '';
                if ($live >= $scope->capacity && ! (($this->sets[$k][$member] ?? 0) > $now)) {
                    return new AdmissionReservationResultDTO(false, $scope->name, $scope->ttlSeconds);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::BUCKET) {
                $max = $scope->capacity * $scope->ttlSeconds;
                $b = $this->buckets[$k] ?? ['credit' => $max, 'last' => $now];
                $credit = min($max, $b['credit'] + max(0, $now - $b['last']));
                if ($credit < $scope->ttlSeconds) {
                    return new AdmissionReservationResultDTO(false, $scope->name, $scope->ttlSeconds - $credit);
                }
            }
        }

        if ($request->sharedConfig !== null) {
            $this->configs[$request->verifierClass . ':' . $request->sharedConfig->key] = $request->sharedConfig->value;
        }
        foreach ($request->scopes as $scope) {
            $k = $request->verifierClass . ':' . $scope->kind->value . ':' . $scope->key;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $member = $scope->member ?? ($request->permit !== null ? $request->permit->id : bin2hex(random_bytes(8)));
                $this->counters[$k][$member] = $now + $scope->ttlSeconds;
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER && $scope->member !== null) {
                $this->sets[$k][$scope->member] = $now + $scope->ttlSeconds;
            } elseif ($scope->kind === AdmissionScopeKindEnum::BUCKET) {
                $max = $scope->capacity * $scope->ttlSeconds;
                $b = $this->buckets[$k] ?? ['credit' => $max, 'last' => $now];
                $credit = min($max, $b['credit'] + max(0, $now - $b['last']));
                $this->buckets[$k] = ['credit' => $credit - $scope->ttlSeconds, 'last' => $now];
            }
        }
        if ($request->permit !== null) {
            $p = $request->permit;
            $this->permits[$p->id] = [
                'state' => 'ISSUED',
                'lane' => $p->lane,
                'subject' => $p->subject,
                'operation' => $p->operation,
                'deadline' => $p->deadlineAt,
                'grace' => $p->graceSeconds,
                'outcome' => null,
            ];
        }

        return new AdmissionReservationResultDTO(true);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        foreach ($outcome->scopes as $scope) {
            $k = $outcome->verifierClass . ':' . $scope->kind->value . ':' . $scope->key;
            if ($scope->kind === AdmissionScopeKindEnum::SET_SIZE && $scope->member !== null) {
                if ($outcome->outcome === AdmissionOutcomeKindEnum::FAILURE) {
                    $this->sets[$k][$scope->member] = $now + $scope->ttlSeconds;
                } elseif (! $scope->successKeepsSettled) {
                    unset($this->sets[$k][$scope->member]);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::COUNTER && $scope->member !== null) {
                if ($outcome->outcome === AdmissionOutcomeKindEnum::SUCCESS || $scope->releasesOnFailure) {
                    unset($this->counters[$k][$scope->member]);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::BUCKET && $outcome->outcome === AdmissionOutcomeKindEnum::SUCCESS) {
                $max = $scope->capacity * $scope->ttlSeconds;
                $b = $this->buckets[$k] ?? ['credit' => $max, 'last' => $now];
                $credit = min($max, $b['credit'] + max(0, $now - $b['last']) + $scope->ttlSeconds);
                $this->buckets[$k] = ['credit' => $credit, 'last' => $now];
            }
        }
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        $record = $this->permits[$permitId] ?? null;
        if ($record === null || $now >= $record['deadline'] + $record['grace']) {
            return AdmissionPermitClaimEnum::UNKNOWN;
        }
        if ($record['lane'] !== $lane || $record['subject'] !== $subject || $record['operation'] !== $operation) {
            return AdmissionPermitClaimEnum::REJECTED;
        }
        if ($record['state'] === 'CLAIMED') {
            return $record['outcome'] === $outcome->value ? AdmissionPermitClaimEnum::DUPLICATE : AdmissionPermitClaimEnum::CONFLICT;
        }
        if ($now >= $record['deadline']) {
            return AdmissionPermitClaimEnum::LATE;
        }
        $this->permits[$permitId]['state'] = 'CLAIMED';
        $this->permits[$permitId]['outcome'] = $outcome->value;

        return AdmissionPermitClaimEnum::ACCEPTED;
    }

    public function claimAdmissionPermitWithOutcome(
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeDTO $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        $claim = $this->claimAdmissionPermit($outcome->permitId ?? '', $lane, $subject, $operation, $outcome->outcome, $now);
        if ($claim === AdmissionPermitClaimEnum::ACCEPTED) {
            $this->applyAdmissionOutcome($outcome, $now);
        }

        return $claim;
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $now = $this->clock->now()->getTimestamp();
        $cur = $this->scores[$key] ?? null;
        if ($cur === null || $cur['expiresAt'] <= $now) {
            $this->scores[$key] = ['value' => $amount, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];

            return $amount;
        }
        $cur['value'] += $amount;
        $cur['updatedAt'] = $now;
        $this->scores[$key] = $cur;

        return $cur['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $cur = $this->scores[$key] ?? null;

        return ($cur === null || $cur['expiresAt'] <= $now) ? null : new RateLimitStateDTO($cur['value'], $cur['updatedAt']);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->scores[$key] = ['value' => $value, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        $cur = $this->get($key);
        $matches = $expectedValue === null ? $cur === null : ($cur !== null && $cur->value === $expectedValue && $cur->updatedAt === $expectedUpdatedAt);
        if (! $matches) {
            return null;
        }
        $this->set($key, $newValue, $ttlSeconds);

        return $newValue;
    }

    public function replaceScore(string $key, int $value, int $ttlSeconds): ?RateLimitStateDTO
    {
        $prev = $this->get($key);
        $this->set($key, $value, $ttlSeconds);

        return $prev;
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->blocks[$key] = ['level' => $level, 'expiresAt' => $this->clock->now()->getTimestamp() + $durationSeconds];
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $b = $this->blocks[$key] ?? null;

        return ($b === null || $b['expiresAt'] <= $this->clock->now()->getTimestamp()) ? null : new BlockStateDTO($b['level'], $b['expiresAt']);
    }

    public function peekBlock(string $key): ?BlockStateDTO
    {
        return $this->checkBlock($key);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $b = $this->budgets[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        return ($b === null || $now >= $b['epochStart'] + $b['epochDuration']) ? null : new BudgetStateDTO($b['count'], $b['epochStart']);
    }

    public function peekBudget(string $key): ?BudgetStateDTO
    {
        return $this->getBudget($key);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $b = $this->budgets[$key] ?? null;
        if ($b === null || $now >= $b['epochStart'] + $b['epochDuration']) {
            $b = ['count' => $amount, 'epochStart' => $now, 'epochDuration' => $epochDurationSeconds];
        } else {
            $b['count'] += $amount;
        }
        $this->budgets[$key] = $b;

        return new BudgetStateDTO($b['count'], $b['epochStart']);
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount = 1): BudgetStateDTO
    {
        $existing = $this->getBudget($key);
        if ($existing !== null) {
            return $this->incrementBudget($key, $epochDurationSeconds, $amount);
        }
        $now = $this->clock->now()->getTimestamp();
        if ($now < $seed->epochStart + $epochDurationSeconds) {
            $this->budgets[$key] = ['count' => $seed->count + $amount, 'epochStart' => $seed->epochStart, 'epochDuration' => $epochDurationSeconds];

            return new BudgetStateDTO($seed->count + $amount, $seed->epochStart);
        }

        return $this->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): HardBlockCycleResultDTO
    {
        $this->blocks[$currentKey] = ['level' => $level, 'expiresAt' => $now + $durationSeconds];

        return new HardBlockCycleResultDTO(true, 1, false, 0);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): DecayPauseStateDTO
    {
        return new DecayPauseStateDTO(0, 0);
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO
    {
        $s = $this->get($currentKey);

        return $s === null ? null : new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $s->value, $s->updatedAt, $this->scores[$currentKey]['expiresAt'], 1, null);
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
    {
        $this->set($currentKey, $newValue, $ttlSeconds);
        $now = $this->clock->now()->getTimestamp();

        return new GenerationBoundScoreMutationDTO(true, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $newValue, $now, $now + $ttlSeconds, 1));
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $this->blocks[$currentKey] = ['level' => $level, 'expiresAt' => $now + $durationSeconds];

        return new PunishmentLifecycleTransitionDTO(true, new HardBlockCycleResultDTO(true, 1, false, 0), new BlockStateDTO($level, $now + $durationSeconds), new PostPunishmentReentryStateDTO($proposedLifecycleId, $now + $durationSeconds));
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        return false;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /** @param array<string, int> $entries */
    private function liveCount(array $entries, int $now): int
    {
        $count = 0;
        foreach ($entries as $exp) {
            if ($exp > $now) {
                $count++;
            }
        }

        return $count;
    }
}

final class AdmissionExampleCorrelationStore implements BoundedCorrelationSnapshotRotationStoreInterface, E1KeyspaceStoreInterface
{
    private bool $e1View = false;
    private ?self $accountView = null;

    public function __construct(private readonly ClockInterface $clock) {}

    public function e1Keyspace(): static
    {
        if ($this->e1View) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->e1View = true;
            $view->accountView = null;
            $this->accountView = $view;
        }

        return $this->accountView;
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 1;
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        return 1;
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return new BoundedDistinctResultDTO(1, true);
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return new BoundedDistinctResultDTO(1, true);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        return new BoundedDistinctSnapshotDTO(1, true, true, [$item], $this->clock->now()->getTimestamp() + $ttlSeconds);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        return new BoundedDistinctSnapshotDTO(1, true, true, [$currentMember], $this->clock->now()->getTimestamp() + $ttlSeconds);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 1;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return 1;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}

final class AdmissionExampleCircuitStore implements CircuitBreakerProbeStoreInterface, CircuitBreakerStateCasStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }

    /** @return array{0: ?CircuitBreakerStateDTO, 1: string} */
    public function loadWithVersion(string $policyName): array
    {
        $s = $this->load($policyName);

        return [$s, $s === null ? '' : '1'];
    }

    public function saveIfVersion(string $policyName, string $expectedVersion, CircuitBreakerStateDTO $state): bool
    {
        $this->save($policyName, $state);

        return true;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        return true;
    }
}

final class AdmissionExampleSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void {}
}

// ============================================================================
// 1. Provision stable secrets outside source code (env/secret manager).
//    Generate `admissionStateSecret` ONCE with `random_bytes(32)` (or more)
//    when creating an isolated Admission trust domain; never hard-code real
//    production secrets in source control.
// ============================================================================
$admissionTrustDomain = 'auth-edge.example.test';
$admissionStateSecret = random_bytes(RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES);
$outerKeyV1 = 'example-outer-key-v1';
$outerKeyV2 = 'example-outer-key-v2';
$outerKeyV3 = 'example-outer-key-v3';
$fingerprintSecret = 'example-fingerprint-secret';
$bindingKeyV1 = 'example-binding-key-v1';
$bindingKeyV2 = 'example-binding-key-v2';

$clock = new AdmissionExampleClock();
$now = $clock->now()->getTimestamp();
$store = new AdmissionExampleStore($clock);
$correlation = new AdmissionExampleCorrelationStore($clock);
$circuit = new AdmissionExampleCircuitStore();
$signals = new AdmissionExampleSignalEmitter();

$buildRuntime = static function (RateLimiterConfig $config) use ($store, $correlation, $circuit, $signals, $clock): CompositeRateLimiterRuntimeInterface {
    return (new RateLimiterBuilder($config, $store, $correlation, $circuit, $signals))
        ->withPolicy(new LoginProtectionAdmissionPolicy())
        ->withClock($clock)
        ->build();
};

// ============================================================================
// 2. Unified setup path: one RateLimiterConfig drives both runtime and codec.
//    No manual framing, HMAC, or Redis key layout anywhere in consumer code.
// ============================================================================
$configV1 = new RateLimiterConfig(
    keySecret: $outerKeyV1,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    bindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: $admissionTrustDomain,
    admissionStateSecret: $admissionStateSecret,
);
$runtime = $buildRuntime($configV1);
$codec = AdmissionCredentialCodec::fromConfig($configV1);

// 3. Open the fleet fence once all workers run this configuration.
if (! $runtime instanceof AdmissionFenceControlInterface || ! $runtime instanceof AdmissionBindingEpochInterface || ! $runtime instanceof AdmissionBindingControlInterface) {
    throw new RateLimiterException('Expected admission control interfaces on composite runtime.');
}
$fence = $runtime->openAdmissionFence('login');

// 4. Unproven login attempt: checkOnly -> verify password -> report outcome with same context & permit.
$accountId = 'acct-owner-100';
$deviceInstance = 'device-laptop-01';
$sharedSourceIp = '198.51.100.55';
$initialCtx = new RateLimitContextDTO($sharedSourceIp, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance);
$check = $runtime->limit($initialCtx, RateLimitCommand::checkOnly('login_protection'));
$permit = $check->metadata?->permit;
if (! $check->isAllowed() || $permit === null) {
    throw new RateLimiterException('Expected initial unproven login admission.');
}
// Trusted authentication succeeded -> settle the permit as success.
$runtime->limit($initialCtx, new RateLimitCommand('login_protection', 1, false, false, true, $permit));

// 5. After trusted authentication success, read `bindingEpoch($accountId)` and issue a v2 credential.
$epoch0 = $runtime->bindingEpoch($accountId);
$credEpoch0 = $codec->encode(new AdmissionCredentialDTO(
    account: $accountId,
    subject: $accountId,
    budgetGroups: ['login'],
    instance: $deviceInstance,
    jti: bin2hex(random_bytes(8)),
    expiresAt: $now + 86400,
    boundAt: $now,
    epoch: $epoch0,
));

// 6. Account revocation (e.g., password change) + re-issue to eligible device.
$runtime->revokeAccountBinding($accountId);
$epoch1 = $runtime->bindingEpoch($accountId);
$credEpoch1 = $codec->encode(new AdmissionCredentialDTO(
    account: $accountId,
    subject: $accountId,
    budgetGroups: ['login'],
    instance: $deviceInstance,
    jti: bin2hex(random_bytes(8)),
    expiresAt: $now + 86400,
    boundAt: $now,
    epoch: $epoch1,
));

// 7. Independent routine rotations:
//    - Rotate outer `keySecret` twice (V1 -> V2/V1 -> V3/V2) and reopen fence each step.
//    - Rotate `bindingKeySecret` independently (V1 -> V2/V1).
//    - `admissionStateSecret` stays STABLE: epoch=1 revocation survives both outer rotations!
$configV2 = new RateLimiterConfig(
    keySecret: $outerKeyV2,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    previousKeySecret: $outerKeyV1,
    bindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: $admissionTrustDomain,
    admissionStateSecret: $admissionStateSecret,
);
$runtimeV2 = $buildRuntime($configV2);
if ($runtimeV2 instanceof AdmissionFenceControlInterface) {
    $runtimeV2->openAdmissionFence('login');
}

$configV3 = new RateLimiterConfig(
    keySecret: $outerKeyV3,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    previousKeySecret: $outerKeyV2,
    bindingKeySecret: $bindingKeyV2,
    previousBindingKeySecret: $bindingKeyV1,
    admissionTrustDomain: $admissionTrustDomain,
    admissionStateSecret: $admissionStateSecret,
);
$runtimeV3 = $buildRuntime($configV3);
if (! $runtimeV3 instanceof AdmissionFenceControlInterface || ! $runtimeV3 instanceof AdmissionBindingEpochInterface) {
    throw new RateLimiterException('Expected admission control interfaces after rotation.');
}
$runtimeV3->openAdmissionFence('login');
$epochAfterTwoOuterRotations = $runtimeV3->bindingEpoch($accountId);

// Exhaust unproven source capacity (24) under V3 to prove epoch-0 stays on Lane U while epoch-1 stays on Lane P.
for ($i = 0; $i < 24; $i++) {
    $filler = new RateLimitContextDTO($sharedSourceIp, 'Mozilla/5.0 Chrome/126', 'filler-' . $i);
    $r = $runtimeV3->limit($filler, RateLimitCommand::checkOnly('login_protection'));
    $runtimeV3->limit($filler, new RateLimitCommand('login_protection', 1, false, false, true, $r->metadata?->permit));
}

$oldCredAttempt = $runtimeV3->limit(
    new RateLimitContextDTO($sharedSourceIp, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance, admissionCredential: $credEpoch0),
    RateLimitCommand::checkOnly('login_protection'),
);
$reissuedCtx = new RateLimitContextDTO($sharedSourceIp, 'Mozilla/5.0 Chrome/126', $accountId, clientInstanceId: $deviceInstance, admissionCredential: $credEpoch1);
$reissuedAttempt = $runtimeV3->limit($reissuedCtx, RateLimitCommand::checkOnly('login_protection'));
$runtimeV3->limit($reissuedCtx, new RateLimitCommand('login_protection', 1, false, false, true, $reissuedAttempt->metadata?->permit));

// 8. Loss of persistence continuity (distinct from routine rotation):
//    If Redis/state continuity is lost, DO NOT reuse the previous `admissionStateSecret`.
//    Establish a fresh isolated scope with a new trust domain and a newly generated >=32-byte
//    `admissionStateSecret`, so legacy credentials from the lost scope cannot verify as epoch 0.
$freshRecoveryConfig = new RateLimiterConfig(
    keySecret: $outerKeyV3,
    fingerprintSecret: $fingerprintSecret,
    environmentScope: 'production',
    bindingKeySecret: $bindingKeyV2,
    admissionTrustDomain: 'auth-edge-recovery-2026-11-26.example.test',
    admissionStateSecret: random_bytes(RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES),
);
$freshCodec = AdmissionCredentialCodec::fromConfig($freshRecoveryConfig);
$legacyVerdictInFreshScope = $freshCodec->verify($credEpoch1, $accountId, $accountId, 'login', $deviceInstance, $now, 0)->verdict->value;

echo json_encode([
    'fenceOpened' => $fence,
    'epochBeforeRevoke' => $epoch0,
    'epochAfterRevoke' => $epoch1,
    'epochAfterTwoOuterRotations' => $epochAfterTwoOuterRotations,
    'revokedEpoch0CauseOnExhaustedSource' => $oldCredAttempt->metadata?->cause,
    'reissuedEpoch1DecisionOnExhaustedSource' => $reissuedAttempt->decision,
    'priorCredentialVerdictInFreshIsolatedScope' => $legacyVerdictInFreshScope,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
