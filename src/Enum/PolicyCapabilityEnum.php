<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * Package-owned reusable runtime capabilities for score-based policies.
 */
enum PolicyCapabilityEnum: string
{
    case CREDENTIAL_SPRAY = 'credential_spray';
    case DISTRIBUTED_ACCOUNT = 'distributed_account';
    case TRUSTED_AUTHENTICATION = 'trusted_authentication';
    case API_OVERUSE = 'api_overuse';

    /**
     * Opt-in to the atomic admission reservation (DEC-024) for `pre+out` Hosts (DEC-023). Authentication-related.
     * Declared by `LoginProtectionAdmissionPolicy` and by custom policies that opt in; no default preset declares it.
     */
    case ADMISSION_RESERVATION = 'admission_reservation';
}
