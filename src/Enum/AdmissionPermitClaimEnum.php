<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * Result of claiming a permit with an outcome. The first outcome is accepted; nothing else is ever scored. Only
 * `ACCEPTED` changes any state (`AdmissionPermitStoreInterface::claimAdmissionPermit()` states each condition).
 */
enum AdmissionPermitClaimEnum: string
{
    /** The permit was unclaimed and within its deadline; it is now claimed with this outcome. */
    case ACCEPTED = 'accepted';

    /** The permit was already claimed with the same outcome. */
    case DUPLICATE = 'duplicate';

    /** The permit was already claimed with the other outcome. */
    case CONFLICT = 'conflict';

    /** The permit exists but was created for another lane, subject or operation. */
    case REJECTED = 'rejected';

    /** The permit is unclaimed and its deadline has passed, but its grace has not. */
    case LATE = 'late';

    /** No such permit, or its deadline plus grace has passed. */
    case UNKNOWN = 'unknown';
}
