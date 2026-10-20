<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;

/**
 * Declaration a policy makes when it opts into `PolicyCapabilityEnum::ADMISSION_RESERVATION`.
 *
 * It is a separate additive interface: `BlockPolicyInterface` does not gain a method. It carries no
 * budget, threshold or window value; those are package-owned.
 *
 * `LoginProtectionAdmissionPolicy` is the official policy that implements it; no default preset does.
 */
interface AdmissionReservationPolicyInterface
{
    /** The Host integration mode this policy is used in. Only `pre+out` is eligible. */
    public function getAdmissionHostMode(): AdmissionHostModeEnum;

    /**
     * The verifier class shared by every policy that calls the same verifier (for example login,
     * re-authentication and password change). Admission state is keyed by this group, not by the policy name.
     * Must not be empty.
     */
    public function getAdmissionBudgetGroup(): string;
}
