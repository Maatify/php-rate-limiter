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
    /**
     * Claim the permit `$permitId` for one outcome, judged at `$now` (Unix seconds, non-negative).
     *
     * `$lane`, `$subject` and `$operation` must match the values the permit was created with; none may be empty.
     * The result is one of `AdmissionPermitClaimEnum`:
     * - `UNKNOWN`: there is no such permit, or `$now` is at or past its deadline plus its grace. Nothing is changed.
     * - `REJECTED`: the permit exists but was created for another lane, subject or operation. Nothing is changed.
     * - `DUPLICATE`: the permit was already claimed with the same outcome. Nothing is changed.
     * - `CONFLICT`: the permit was already claimed with the other outcome. Nothing is changed.
     * - `LATE`: the permit is unclaimed but `$now` is at or past its deadline, inside the grace. Nothing is changed.
     * - `ACCEPTED`: the permit was unclaimed and within its deadline; it is now claimed with `$outcome`, which is
     *   the only outcome the permit will ever accept.
     *
     * The compare-and-set is atomic: of two concurrent claims at most one is `ACCEPTED`. This method records the
     * claim only; applying the outcome to the admission scopes is a separate operation
     * (`AdmissionReservationStoreInterface::applyAdmissionOutcome()`), or one atomic step through
     * `AtomicAdmissionPermitStoreInterface::claimAdmissionPermitWithOutcome()`.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when an identifier is empty, `$now` is negative or
     *         not representable, or the stored permit record is malformed
     */
    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum;
}
