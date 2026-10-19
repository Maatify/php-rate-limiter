<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * Host integration mode a policy declares when it opts into the admission capability (DEC-023 point 3, A-2).
 *
 * Only the `pre+out` mode is eligible, so it is the only case: `checkOnly` followed by a recorded outcome.
 * `pre` (checkOnly only) and `out` (record only) Hosts are deliberately not representable.
 */
enum AdmissionHostModeEnum: string
{
    case PRE_AND_OUT = 'pre_and_out';
}
