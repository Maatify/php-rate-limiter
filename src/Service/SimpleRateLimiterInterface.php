<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Consumer entrypoint for generic/simple fixed-window throttling (DEC-013).
 *
 * The current contract exposes exactly one atomic operation: there is no
 * separate non-mutating check() prior to consume(), no peek(), and no
 * consumeMany().
 */
interface SimpleRateLimiterInterface
{
    /**
     * Atomically consume the requested positive cost from the named policy's
     * fixed window for the given subject and return the resulting typed
     * decision. The default cost preserves existing callers; limit and
     * remaining are quota/consumption units, not merely call counts.
     *
     * @throws RateLimiterException When the policy name is unregistered, the
     *     subject is blank, cost is not positive, a required rotation-
     *     migration storage capability is missing, or the effective reset
     *     boundary cannot be represented as a PHP integer. Contract failures
     *     are exceptions, not quota decisions or FAIL_CLOSED results.
     */
    public function consume(string $policyName, string $subject, int $cost = 1): SimpleRateLimitResultDTO;
}
