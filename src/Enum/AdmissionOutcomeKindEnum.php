<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/** The recorded outcome of an admitted attempt. */
enum AdmissionOutcomeKindEnum: string
{
    case FAILURE = 'failure';
    case SUCCESS = 'success';
}
