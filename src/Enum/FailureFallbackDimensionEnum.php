<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * Package-owned finite scopes a bounded failure-fallback rule may enforce.
 */
enum FailureFallbackDimensionEnum: string
{
    case ACCOUNT = 'account';
    case IP_PREFIX = 'ip_prefix';
    case IP_PREFIX_NORMALIZED_USER_AGENT = 'ip_prefix_normalized_user_agent';
}
