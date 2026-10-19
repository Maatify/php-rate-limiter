<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

/** Reservation capability without the permit capability (a store that fails the OD-1 requirement). */
final class AdmissionReservationOnlyStore extends FullCapabilityInMemoryStore implements AdmissionCapableFullStoreInterface
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
}
