<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Repository\SharedAdmissionConfigStoreInterface;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\AtomicAdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

/**
 * Executable specification of the admission store contract (contract §2.4): the full-capability reference store plus the
 * admission and permit operations, in PHP arrays and on the caller's `now` only. The Redis store must give identical results
 * on the shared contract fixtures. Windowed counters and failure sets are sliding windows on the caller's clock (#185, DEC-033):
 * each member carries its expiry (its admission or failure time plus the scope TTL) and is live while that is after `now`;
 * a due pending member counts until its deadline is a TTL old.
 */
final class AdmissionInMemoryReferenceStore extends FullCapabilityInMemoryStore implements
    AdmissionCapableFullStoreInterface,
    AdmissionPermitStoreInterface,
    AtomicAdmissionPermitStoreInterface,
    SharedAdmissionConfigStoreInterface
{
    /** @var array<string, string> registered shared admission configuration (DEC-038), per budget group and key */
    private array $configs = [];

    /** @var array<string, array<string, int>> member => expiry score, per counter */
    private array $counters = [];

    /** @var array<string, array<string, int>> member => expiry score, per set */
    private array $devices = [];

    /** @var array<string, array<string, int>> member => expiry score, per failure set */
    private array $sets = [];

    /** @var array<string, array<string, int>> member => permit deadline, pending members of a failure set */
    private array $pending = [];

    /** @var array<string, array{credit: int, last: int}> */
    private array $buckets = [];

    /** @var array<string, array{lane: string, subject: string, operation: string, deadline: int, grace: int, outcome: ?string}> */
    private array $permits = [];

    /** @var array<string, int> */
    private array $epochs = [];

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        AdmissionReferenceInput::reservation($request, $now, supportsSettlement: true);
        $class = $request->verifierClass;
        $config = $request->sharedConfig;
        if ($config !== null) {
            $stored = $this->configs[$class . "\0" . $config->key] ?? ($config->previousKey === null ? null : ($this->configs[$class . "\0" . $config->previousKey] ?? null));
            if ($stored !== null && $stored !== $config->value) {
                throw new AdmissionConfigurationException('The admission shared configuration differs from the registered one.');
            }
        }
        $credits = [];
        $firstRefusal = null;
        $longestRetry = 0;
        foreach ($request->scopes as $scope) {
            $own = $class . "\0" . $scope->key;
            $previous = $scope->previousKey === null || $scope->previousKey === $scope->key ? null : $class . "\0" . $scope->previousKey;
            $retry = null;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $total = $this->liveCounterCount($own, $now) + ($previous === null ? 0 : ($this->liveCounterCount($previous, $now)));
                if ($total >= $scope->capacity) {
                    $allLiveScores = [];
                    foreach ($this->counters[$own] ?? [] as $exp) {
                        if ($exp > $now) {
                            $allLiveScores[] = $exp;
                        }
                    }
                    if ($previous !== null) {
                        foreach ($this->counters[$previous] ?? [] as $exp) {
                            if ($exp > $now) {
                                $allLiveScores[] = $exp;
                            }
                        }
                    }
                    sort($allLiveScores);
                    $excess = $total - $scope->capacity + 1;
                    $targetScore = $allLiveScores[$excess - 1] ?? $now + 1;

                    $retry = max(1, $targetScore - $now);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                $total = $this->liveCount($own, $now) + ($previous === null ? 0 : $this->liveCount($previous, $now));
                if (! $this->deviceExists($scope, $own, $previous, $now) && $total >= $scope->capacity) {
                    $allLiveScores = [];
                    foreach ($this->devices[$own] ?? [] as $exp) {
                        if ($exp > $now) {
                            $allLiveScores[] = $exp;
                        }
                    }
                    if ($previous !== null) {
                        foreach ($this->devices[$previous] ?? [] as $exp) {
                            if ($exp > $now) {
                                $allLiveScores[] = $exp;
                            }
                        }
                    }
                    sort($allLiveScores);
                    $excess = $total - $scope->capacity + 1;
                    $targetScore = $allLiveScores[$excess - 1] ?? $now + 1;

                    $retry = max(1, $targetScore - $now);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::SET_SIZE) {
                $allLiveScores = $this->settledExpiries($own, $now, $scope);
                $size = count($allLiveScores);
                if (! $this->isSettledMember($own, (string) $scope->member, $now, $scope) && $size >= $scope->capacity) {
                    sort($allLiveScores);
                    $excess = $size - $scope->capacity + 1;
                    $targetScore = $allLiveScores[$excess - 1] ?? $now + 1;

                    $retry = max(1, $targetScore - $now);
                }
            } else {
                $credit = $this->bucketCredit($own, $scope, $now);
                $credits[$own] = $credit;
                if ($credit < $scope->ttlSeconds) {
                    $retry = $scope->ttlSeconds - $credit;
                }
            }
            if ($retry !== null) {
                $firstRefusal ??= $scope->name;
                $longestRetry = max($longestRetry, $retry);
            }
        }
        if ($firstRefusal !== null) {
            return new AdmissionReservationResultDTO(false, $firstRefusal, $longestRetry);
        }
        $permit = $request->permit;
        if ($permit !== null && isset($this->permits[$permit->id])) {
            return new AdmissionReservationResultDTO(false, 'permit_exists');
        }
        foreach ($request->scopes as $scope) {
            $own = $class . "\0" . $scope->key;
            $previous = $scope->previousKey === null || $scope->previousKey === $scope->key ? null : $class . "\0" . $scope->previousKey;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $member = $scope->member ?? $permit?->id;
                $this->counters[$own] = array_filter($this->counters[$own] ?? [], static fn(int $expiry): bool => $expiry > $now);
                $this->counters[$own][$member] = $now + $scope->ttlSeconds;
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER && ! $this->deviceExists($scope, $own, $previous, $now)) {
                $this->devices[$own] = array_filter($this->devices[$own] ?? [], static fn(int $expiry): bool => $expiry > $now);
                $this->devices[$own][(string) $scope->member] = $now + $scope->ttlSeconds;
            } elseif ($scope->kind === AdmissionScopeKindEnum::BUCKET) {
                $this->buckets[$own] = ['credit' => $credits[$own] - $scope->ttlSeconds, 'last' => $now];
            } elseif ($scope->kind === AdmissionScopeKindEnum::SET_SIZE && $scope->settlesOnExpiry && $permit !== null && ! $this->liveInSet($own, (string) $scope->member, $now)) {
                $member = (string) $scope->member;
                $this->pending[$own] = array_filter($this->pending[$own] ?? [], static fn(int $deadline): bool => $deadline > $now - $scope->ttlSeconds);
                $this->pending[$own][$member] = min($this->pending[$own][$member] ?? $permit->deadlineAt, $permit->deadlineAt);
            }
        }
        if ($permit !== null) {
            $this->permits[$permit->id] = [
                'lane' => $permit->lane,
                'subject' => $permit->subject,
                'operation' => $permit->operation,
                'deadline' => $permit->deadlineAt,
                'grace' => $permit->graceSeconds,
                'outcome' => null,
            ];
        }

        if ($config !== null) {
            $this->configs[$class . "\0" . $config->key] ??= $config->value;
        }

        return new AdmissionReservationResultDTO(true);
    }

    public function rebindAdmissionConfig(string $budgetGroup, AdmissionSharedConfigDTO $config): void
    {
        $this->configs[$budgetGroup . "\0" . $config->key] = $config->value;
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        AdmissionReferenceInput::outcome($outcome, $now);
        $success = $outcome->outcome === AdmissionOutcomeKindEnum::SUCCESS;
        foreach ($outcome->scopes as $scope) {
            $own = $outcome->verifierClass . "\0" . $scope->key;
            if ($scope->kind === AdmissionScopeKindEnum::SET_SIZE) {
                $member = (string) $scope->member;
                if ($success) {
                    if (! $scope->settlesOnExpiry || ! $scope->successKeepsSettled) {
                        unset($this->sets[$own][$member]);
                    }
                    if ($scope->settlesOnExpiry && isset($this->pending[$own][$member]) && $this->pending[$own][$member] > $now) {
                        unset($this->pending[$own][$member]);
                    }
                } else {
                    $this->sets[$own] = array_filter($this->sets[$own] ?? [], static fn(int $expiry): bool => $expiry > $now);
                    $this->sets[$own][$member] = $now + $scope->ttlSeconds;
                    if ($scope->settlesOnExpiry) {
                        unset($this->pending[$own][$member]);
                    }
                }
            } elseif (($success || $scope->releasesOnFailure) && $scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $member = $scope->member ?? ($outcome->permitId ?? '');
                if ($member !== '') {
                    unset($this->counters[$own][$member]);
                }
            } elseif ($success && $scope->kind === AdmissionScopeKindEnum::BUCKET && isset($this->buckets[$own])) {
                $credit = $this->bucketCredit($own, $scope, $now) + $scope->ttlSeconds;
                if ($credit >= $scope->capacity * $scope->ttlSeconds) {
                    unset($this->buckets[$own]);
                } else {
                    $this->buckets[$own] = ['credit' => $credit, 'last' => $now];
                }
            }
        }
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->epochs[$key] ?? ($previousKey === null || $previousKey === $key ? 0 : ($this->epochs[$previousKey] ?? 0));
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        $previous = $previousKey === null || $previousKey === $key ? 0 : ($this->epochs[$previousKey] ?? 0);

        return $this->epochs[$key] = max($this->epochs[$key] ?? 0, $previous) + 1;
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        AdmissionReferenceInput::claim($permitId, $lane, $subject, $operation, $now);
        $record = $this->permits[$permitId] ?? null;
        if ($record === null || $now >= $record['deadline'] + $record['grace']) {
            return AdmissionPermitClaimEnum::UNKNOWN;
        }
        if ($record['lane'] !== $lane || $record['subject'] !== $subject || $record['operation'] !== $operation) {
            return AdmissionPermitClaimEnum::REJECTED;
        }
        if ($record['outcome'] !== null) {
            return $record['outcome'] === $outcome->value ? AdmissionPermitClaimEnum::DUPLICATE : AdmissionPermitClaimEnum::CONFLICT;
        }
        if ($now >= $record['deadline']) {
            return AdmissionPermitClaimEnum::LATE;
        }
        $this->permits[$permitId]['outcome'] = $outcome->value;

        return AdmissionPermitClaimEnum::ACCEPTED;
    }

    private function liveCounterCount(string $key, int $now): int
    {
        return count(array_filter($this->counters[$key] ?? [], static fn(int $expiry): bool => $expiry > $now));
    }

    private function liveInSet(string $own, string $member, int $now): bool
    {
        return ($this->sets[$own][$member] ?? 0) > $now;
    }

    /** A pending deadline is a failure once due, until it is a TTL old. */
    private function duePending(string $own, string $member, int $now, AdmissionScopeDTO $scope): bool
    {
        $deadline = $this->pending[$own][$member] ?? null;

        return $deadline !== null && $deadline <= $now && $deadline > $now - $scope->ttlSeconds;
    }

    private function isSettledMember(string $own, string $member, int $now, AdmissionScopeDTO $scope): bool
    {
        return $this->liveInSet($own, $member, $now) || ($scope->settlesOnExpiry && $this->duePending($own, $member, $now, $scope));
    }

    /** @return list<int> One expiry per subject counted in the settled set. */
    private function settledExpiries(string $own, int $now, AdmissionScopeDTO $scope): array
    {
        $expiries = array_filter($this->sets[$own] ?? [], static fn(int $expiry): bool => $expiry > $now);
        if ($scope->settlesOnExpiry) {
            foreach ($this->pending[$own] ?? [] as $member => $deadline) {
                if ($this->duePending($own, (string) $member, $now, $scope)) {
                    $expiries[$member] = max($expiries[$member] ?? 0, $deadline + $scope->ttlSeconds);
                }
            }
        }

        return array_values($expiries);
    }

    public function claimAdmissionPermitWithOutcome(
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeDTO $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        $permitId = AdmissionReferenceInput::atomicClaim($lane, $subject, $operation, $outcome, $now);
        if (! isset($this->permits[$permitId])) {
            return AdmissionPermitClaimEnum::UNKNOWN;
        }
        $p = $this->permits[$permitId];
        if ($now >= $p['deadline'] + $p['grace']) {
            return AdmissionPermitClaimEnum::UNKNOWN;
        }
        if ($p['lane'] !== $lane || $p['subject'] !== $subject || $p['operation'] !== $operation) {
            return AdmissionPermitClaimEnum::REJECTED;
        }
        if ($p['outcome'] !== null) {
            return $p['outcome'] === $outcome->outcome->value
                ? AdmissionPermitClaimEnum::DUPLICATE
                : AdmissionPermitClaimEnum::CONFLICT;
        }
        if ($now >= $p['deadline']) {
            return AdmissionPermitClaimEnum::LATE;
        }

        $this->applyAdmissionOutcome($outcome, $now);
        $this->permits[$permitId]['outcome'] = $outcome->outcome->value;

        return AdmissionPermitClaimEnum::ACCEPTED;
    }

    private function deviceExists(AdmissionScopeDTO $scope, string $own, ?string $previous, int $now): bool
    {
        if (($this->devices[$own][(string) $scope->member] ?? 0) > $now) {
            return true;
        }

        if ($scope->previousMember !== null && ($this->devices[$own][$scope->previousMember] ?? 0) > $now) {
            return true;
        }

        if ($previous !== null) {
            if ($scope->previousMember !== null && ($this->devices[$previous][$scope->previousMember] ?? 0) > $now) {
                return true;
            }
            if (($this->devices[$previous][(string) $scope->member] ?? 0) > $now) {
                return true;
            }
        }

        return false;
    }

    private function liveCount(string $set, int $now): int
    {
        return count(array_filter($this->devices[$set] ?? [], static fn(int $expiry): bool => $expiry > $now));
    }

    private function bucketCredit(string $own, AdmissionScopeDTO $scope, int $now): int
    {
        $full = $scope->capacity * $scope->ttlSeconds;
        if (! isset($this->buckets[$own])) {
            return $full;
        }

        return min($full, $this->buckets[$own]['credit'] + max(0, $now - $this->buckets[$own]['last']));
    }
}
