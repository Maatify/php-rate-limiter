<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Enum;

/**
 * The admission lane of one request, passed to the evaluation pipeline as a call parameter (never object state).
 *
 * `null` is today's behaviour: no admission layer. `U` is the unproven lane, `P` the proven one (a verified binding).
 * The lane is chosen by the admission runtime from a verified credential, never from a Host flag, a header or an
 * instance id; nothing in the supported composition passes a lane before the admission runtime is activated.
 *
 * @internal Not part of the supported public surface.
 */
enum AdmissionLaneEnum: string
{
    case U = 'U';
    case P = 'P';
}
