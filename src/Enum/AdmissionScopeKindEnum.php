<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/** Kind of one scope inside an atomic admission reservation (shape only; the store operations arrive later). */
enum AdmissionScopeKindEnum: string
{
    /** A window or day counter with a capacity, optionally summed with a read-only previous generation. */
    case COUNTER = 'counter';

    /** A distinct member of a per-subject set with a capacity (current and previous generation sets). */
    case DEVICE_MEMBER = 'device_member';

    /** A set whose size gates a request for a further member (failed accounts of one source). */
    case SET_SIZE = 'set_size';

    /** A refillable allowance with a capacity. */
    case BUCKET = 'bucket';
}
