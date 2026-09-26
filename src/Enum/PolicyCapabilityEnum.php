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
}
