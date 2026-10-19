<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

/**
 * Executable specification of the admission store contract (contract §2.4): the full-capability reference store plus the
 * admission and permit operations, in PHP arrays and on the caller's `now` only. The Redis store must give identical results
 * on the shared contract fixtures. Counter and set lifetimes are physical garbage collection in Redis (the window is part of
 * the caller's key) and are not observable through the caller's clock, so this store does not expire them.
 */
final class AdmissionInMemoryReferenceStore extends FullCapabilityInMemoryStore implements
    AdmissionCapableFullStoreInterface,
    AdmissionPermitStoreInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, array<string, int>> member => expiry score, per set */
    private array $devices = [];

    /** @var array<string, array<string, true>> */
    private array $sets = [];

    /** @var array<string, array{credit: int, last: int}> */
    private array $buckets = [];

    /** @var array<string, array{lane: string, subject: string, operation: string, deadline: int, grace: int, outcome: ?string}> */
    private array $permits = [];

    /** @var array<string, int> */
    private array $epochs = [];

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        $class = $request->verifierClass;
        $credits = [];
        foreach ($request->scopes as $scope) {
            $previous = $scope->previousKey === $scope->key ? null : $scope->previousKey;
            if ($previous !== null && ! in_array($scope->kind, [AdmissionScopeKindEnum::COUNTER, AdmissionScopeKindEnum::DEVICE_MEMBER], true)) {
                throw new RateLimiterException('Only counter and device-member admission scopes can name a previous generation.');
            }
        }
        foreach ($request->scopes as $scope) {
            $own = $class . "\0" . $scope->key;
            $previous = $scope->previousKey === null || $scope->previousKey === $scope->key ? null : $class . "\0" . $scope->previousKey;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                if (($this->counters[$own] ?? 0) + ($previous === null ? 0 : ($this->counters[$previous] ?? 0)) >= $scope->capacity) {
                    return new AdmissionReservationResultDTO(false, $scope->name);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                if (! $this->deviceExists($scope, $own, $previous, $now)
                    && $this->liveCount($own, $now) + ($previous === null ? 0 : $this->liveCount($previous, $now)) >= $scope->capacity) {
                    return new AdmissionReservationResultDTO(false, $scope->name);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::SET_SIZE) {
                $members = $this->sets[$own] ?? [];
                if (! isset($members[(string) $scope->member]) && count($members) >= $scope->capacity) {
                    return new AdmissionReservationResultDTO(false, $scope->name);
                }
            } else {
                $credit = $this->bucketCredit($own, $scope, $now);
                $credits[$own] = $credit;
                if ($credit < $scope->ttlSeconds) {
                    return new AdmissionReservationResultDTO(false, $scope->name, $scope->ttlSeconds - $credit);
                }
            }
        }
        $permit = $request->permit;
        if ($permit !== null && isset($this->permits[$permit->id])) {
            return new AdmissionReservationResultDTO(false, 'permit_exists');
        }
        foreach ($request->scopes as $scope) {
            $own = $class . "\0" . $scope->key;
            $previous = $scope->previousKey === null || $scope->previousKey === $scope->key ? null : $class . "\0" . $scope->previousKey;
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $this->counters[$own] = ($this->counters[$own] ?? 0) + 1;
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER && ! $this->deviceExists($scope, $own, $previous, $now)) {
                $this->devices[$own] = array_filter($this->devices[$own] ?? [], static fn(int $expiry): bool => $expiry > $now);
                $this->devices[$own][(string) $scope->member] = $now + $scope->ttlSeconds;
            } elseif ($scope->kind === AdmissionScopeKindEnum::BUCKET) {
                $this->buckets[$own] = ['credit' => $credits[$own] - $scope->ttlSeconds, 'last' => $now];
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

        return new AdmissionReservationResultDTO(true);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $success = $outcome->outcome === AdmissionOutcomeKindEnum::SUCCESS;
        foreach ($outcome->scopes as $scope) {
            $own = $outcome->verifierClass . "\0" . $scope->key;
            if ($scope->kind === AdmissionScopeKindEnum::SET_SIZE) {
                if ($success) {
                    unset($this->sets[$own][(string) $scope->member]);
                } else {
                    $this->sets[$own][(string) $scope->member] = true;
                }
            } elseif ($success && $scope->kind === AdmissionScopeKindEnum::COUNTER) {
                if (($this->counters[$own] ?? 0) > 0) {
                    $this->counters[$own]--;
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

    private function deviceExists(AdmissionScopeDTO $scope, string $own, ?string $previous, int $now): bool
    {
        if (($this->devices[$own][(string) $scope->member] ?? 0) > $now) {
            return true;
        }

        return $previous !== null && $scope->previousMember !== null && ($this->devices[$previous][$scope->previousMember] ?? 0) > $now;
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
