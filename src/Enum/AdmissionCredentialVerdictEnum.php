<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * Outcome of verifying an admission credential. Anything but VALID means the request is not proven;
 * it is never an exception and never a refusal by itself.
 */
enum AdmissionCredentialVerdictEnum: string
{
    case VALID = 'valid';
    case MALFORMED = 'malformed';
    case INVALID_SIGNATURE = 'invalid_signature';
    case WRONG_SUBJECT = 'wrong_subject';
    case WRONG_BUDGET_GROUP = 'wrong_budget_group';
    case WRONG_INSTANCE = 'wrong_instance';
    case EXPIRED = 'expired';
    case STALE_EPOCH = 'stale_epoch';
}
