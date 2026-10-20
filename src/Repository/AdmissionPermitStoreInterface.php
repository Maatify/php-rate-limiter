<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;

/**
 * Permit capability (tier T1): one outcome is bound to one admission. The permit record is created by
 * `reserveAdmission` and claimed here by compare-and-set; at most one outcome is accepted per permit.
 *
 * `RedisFullCapabilityStore` implements it; a policy that opts in requires it (OD-1).
 */
interface AdmissionPermitStoreInterface extends AdmissionReservationStoreInterface
{
    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum;
}
