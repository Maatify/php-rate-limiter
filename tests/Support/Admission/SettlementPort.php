<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;

/**
 * The three admission operations as closures, so one scenario runs against a store, the merged 5-b2 operations (detector)
 * and a mutation of the production operations (detector) without a second implementation of the scenario.
 */
final readonly class SettlementPort
{
    /**
     * @param \Closure(AdmissionReservationRequestDTO, int): AdmissionReservationResultDTO $reserve
     * @param \Closure(AdmissionOutcomeDTO, int): void $outcome
     * @param \Closure(string, AdmissionOutcomeKindEnum, int): AdmissionPermitClaimEnum $claim
     */
    public function __construct(
        public \Closure $reserve,
        public \Closure $outcome,
        public \Closure $claim,
    ) {}
}
