<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/** Result of claiming a permit with an outcome. The first outcome is accepted; nothing else is ever scored. */
enum AdmissionPermitClaimEnum: string
{
    case ACCEPTED = 'accepted';
    case DUPLICATE = 'duplicate';
    case CONFLICT = 'conflict';
    case REJECTED = 'rejected';
    case LATE = 'late';
    case UNKNOWN = 'unknown';
}
