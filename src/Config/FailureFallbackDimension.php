<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Package-owned finite scopes a bounded failure-fallback rule may enforce.
 */
enum FailureFallbackDimension: string
{
    case ACCOUNT = 'account';
    case IP_PREFIX = 'ip_prefix';
    case IP_PREFIX_NORMALIZED_USER_AGENT = 'ip_prefix_normalized_user_agent';
}
