<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Single read-only operational contract returned by
 * RateLimiterBuilder::buildOperationalReader() (DEC-012).
 *
 * It coordinates score and simple-throttle operational reads over the
 * Builder's currently registered policies, clock, device identity resolver,
 * key configuration, and environment scope, so a Host never has to
 * reconstruct policy semantics on the Production Default Read Path. It does
 * not add operational-read methods to CompositeRateLimiterRuntimeInterface
 * or RateLimiterRuntimeInterface: enforcement and Operational Read remain
 * separate public responsibilities.
 */
interface CompositeRateLimitOperationalReaderInterface
{
    /**
     * Read one registered score policy's operational snapshot by name.
     *
     * @throws RateLimiterException When the policy name is not registered
     *     with the Builder that produced this reader.
     */
    public function readScorePolicy(
        RateLimitContextDTO $context,
        string $policyName,
    ): RateLimitOperationalSnapshotDTO;

    /**
     * Read one registered simple throttle policy's persisted fixed-window
     * state for the given subject.
     *
     * @throws RateLimiterException When the policy name is not registered
     *     with the Builder that produced this reader.
     */
    public function readSimpleThrottle(
        string $policyName,
        string $subject,
    ): SimpleRateLimitOperationalSnapshotDTO;
}
