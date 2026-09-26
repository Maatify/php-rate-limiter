<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Consumer entrypoint for generic/simple fixed-window throttling (DEC-009).
 *
 * Version 1 exposes exactly one atomic operation: there is no separate
 * non-mutating check() prior to consume(), no peek(), no consumeMany(), and
 * no variable cost.
 */
interface SimpleRateLimiterInterface
{
    /**
     * Atomically consume one unit of the named policy's fixed window for the
     * given subject and return the resulting typed decision.
     *
     * @throws RateLimiterException When the policy name is unregistered, the
     *     subject is blank, or a required rotation-migration storage
     *     capability is missing.
     */
    public function consume(string $policyName, string $subject): SimpleRateLimitResultDTO;
}
