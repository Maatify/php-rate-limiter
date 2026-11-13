<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AtomicAdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * A store that `RateLimiterBuilder::build()` accepts for an admission policy (full capability, reservation, permit and atomic
 * claim) and that does NOT implement `SharedAdmissionConfigStoreInterface`: the contract fixture of DEC-038 Decision 7. The
 * admission operations delegate to the reference store, so only the shared-configuration capability is missing.
 */
final class AdmissionStoreWithoutSharedConfig extends FullCapabilityInMemoryStore implements
    AdmissionCapableFullStoreInterface,
    AtomicAdmissionPermitStoreInterface
{
    private readonly AdmissionInMemoryReferenceStore $inner;

    public function __construct(ClockInterface $clock)
    {
        parent::__construct($clock);
        $this->inner = new AdmissionInMemoryReferenceStore($clock);
    }

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        return $this->inner->reserveAdmission($request, $now);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $this->inner->applyAdmissionOutcome($outcome, $now);
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->inner->readBindingEpoch($key, $previousKey);
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->inner->bumpBindingEpoch($key, $previousKey);
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        return $this->inner->claimAdmissionPermit($permitId, $lane, $subject, $operation, $outcome, $now);
    }

    public function claimAdmissionPermitWithOutcome(
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeDTO $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        return $this->inner->claimAdmissionPermitWithOutcome($lane, $subject, $operation, $outcome, $now);
    }
}
