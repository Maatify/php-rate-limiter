<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Read-only contract for inspecting persisted simple fixed-window state
 * (DEC-012). This is strictly a point-in-time inspection; it is not a
 * second enforcement operation and never mutates persisted state.
 */
interface SimpleRateLimitOperationalReaderInterface
{
    /**
     * Read one simple throttle policy's persisted fixed-window state for the
     * given subject without mutating it.
     *
     * @throws RateLimiterException When the policy name is unregistered, the
     *     subject is blank, or the persisted effective reset boundary is not
     *     representable as a PHP integer. This is a read-only contract
     *     failure; it is not an enforcement result.
     */
    public function read(
        string $policyName,
        string $subject,
    ): SimpleRateLimitOperationalSnapshotDTO;
}
