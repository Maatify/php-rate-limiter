<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

/**
 * Declares the admission store capabilities with no behaviour: 5-b1 ships the
 * interfaces only, so every operation fails loudly if anything calls it.
 */
final class AdmissionStubStore extends FullCapabilityInMemoryStore implements
    AdmissionCapableFullStoreInterface,
    AdmissionPermitStoreInterface
{
    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        throw new \LogicException('No admission store operation exists in 5-b1.');
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        throw new \LogicException('No admission store operation exists in 5-b1.');
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        throw new \LogicException('No admission store operation exists in 5-b1.');
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        throw new \LogicException('No admission store operation exists in 5-b1.');
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        throw new \LogicException('No admission store operation exists in 5-b1.');
    }
}
