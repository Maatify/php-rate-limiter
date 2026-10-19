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
 * Admission store that delegates to the reference store and raises a configured throwable on a named operation
 * (`reserve`, `outcome`, `claim`, `readEpoch`, `bumpEpoch`) before any delegation, so nothing is written by the failed call.
 * Records every admission call it receives.
 */
final class FaultInjectingAdmissionStore implements AdmissionPermitStoreInterface
{
    /** @var array<string, \Throwable> */
    public array $fail = [];

    /** @var list<string> */
    public array $calls = [];

    public function __construct(public readonly AdmissionInMemoryReferenceStore $inner) {}

    private function enter(string $operation): void
    {
        $this->calls[] = $operation;
        if (isset($this->fail[$operation])) {
            throw $this->fail[$operation];
        }
    }

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        $this->enter('reserve');

        return $this->inner->reserveAdmission($request, $now);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $this->enter('outcome');
        $this->inner->applyAdmissionOutcome($outcome, $now);
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        $this->enter('readEpoch');

        return $this->inner->readBindingEpoch($key, $previousKey);
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        $this->enter('bumpEpoch');

        return $this->inner->bumpBindingEpoch($key, $previousKey);
    }

    public function claimAdmissionPermit(string $permitId, string $lane, string $subject, string $operation, AdmissionOutcomeKindEnum $outcome, int $now): AdmissionPermitClaimEnum
    {
        $this->enter('claim');

        return $this->inner->claimAdmissionPermit($permitId, $lane, $subject, $operation, $outcome, $now);
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        return $this->inner->increment($key, $ttlSeconds, $amount);
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        return $this->inner->get($key);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->inner->set($key, $value, $ttlSeconds);
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->inner->block($key, $level, $durationSeconds);
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        return $this->inner->checkBlock($key);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        return $this->inner->getBudget($key);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        return $this->inner->incrementBudget($key, $epochDurationSeconds, $amount);
    }

    public function isHealthy(): bool
    {
        return $this->inner->isHealthy();
    }
}
