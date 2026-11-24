<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/** This sampler is loaded only inside the isolated readiness control. */
function microtime(bool $asFloat = false): float|string
{
    if (! $asFloat) {
        return \microtime(false);
    }

    static $samples = 0;

    // Let a wall-clock deadline be set, then simulate a step before it is checked.
    return \microtime(true) + (++$samples === 1 ? 0.0 : 1_000.0);
}
