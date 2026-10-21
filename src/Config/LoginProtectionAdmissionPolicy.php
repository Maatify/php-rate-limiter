<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;

/**
 * The official opt-in variant of the Login policy for the admission reservation (DEC-024, DEC-026; OD-5 of stage 5-c).
 *
 * It is `LoginProtectionPolicy` with the same name, values, thresholds, deltas, budget, fallback and DEC-007 lifecycle,
 * plus `PolicyCapabilityEnum::ADMISSION_RESERVATION` and its declaration: the `pre+out` Host mode (DEC-023) and the
 * budget group `login`, shared by login, re-authentication and password change. A Host selects it explicitly with
 * `RateLimiterBuilder::withPolicy()`; it then replaces `login_protection` under the same name, so the account and device
 * state written before the switch is read on (the migration measured by NM-7). `login_protection` itself does not opt in.
 */
final class LoginProtectionAdmissionPolicy extends LoginProtectionPolicy implements AdmissionReservationPolicyInterface
{
    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return [...parent::getCapabilities(), PolicyCapabilityEnum::ADMISSION_RESERVATION];
    }

    public function getAdmissionHostMode(): AdmissionHostModeEnum
    {
        return AdmissionHostModeEnum::PRE_AND_OUT;
    }

    public function getAdmissionBudgetGroup(): string
    {
        return 'login';
    }
}
