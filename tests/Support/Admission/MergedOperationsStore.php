<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;

/**
 * The store port of the engine over `MergedAdmissionOperations` (the Redis admission operations exactly as merged at d6aea4d):
 * the arm without the permit mechanism of NM-10 (a) on real Redis (5b3b/PLAN.md §11.3). Pure delegation, the same mapping as
 * `RedisFullCapabilityStore`'s admission methods. The score-store methods the interface inherits are never called by the
 * admission runtime; they throw so that any such call is visible.
 */
final class MergedOperationsStore implements AdmissionPermitStoreInterface
{
    public function __construct(private readonly MergedAdmissionOperations $ops) {}

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        return $this->ops->reserve($request, $now);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $this->ops->applyOutcome($outcome, $now);
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->ops->readEpoch($key, $previousKey);
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->ops->bumpEpoch($key, $previousKey);
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        return $this->ops->claimPermit($permitId, $lane, $subject, $operation, $outcome, $now);
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        throw self::unused();
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        throw self::unused();
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        throw self::unused();
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        throw self::unused();
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        throw self::unused();
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        throw self::unused();
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        throw self::unused();
    }

    public function isHealthy(): bool
    {
        throw self::unused();
    }

    private static function unused(): \LogicException
    {
        return new \LogicException('The admission runtime never calls the score-store methods of its admission store.');
    }
}
