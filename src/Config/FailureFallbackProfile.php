<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/** The finite package-owned profiles used for backend-failure fallback. */
enum FailureFallbackProfile: string
{
    case AUTHENTICATION_PRIMARY = 'AUTHENTICATION_PRIMARY';
    case AUTHENTICATION_STEP_UP = 'AUTHENTICATION_STEP_UP';
    case API_OVERUSE = 'API_OVERUSE';
}
