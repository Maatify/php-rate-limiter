<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/** Public runtime boundary for consuming one post-punishment re-entry handoff. */
interface PostPunishmentReentryClaimInterface
{
    /**
     * Atomically consumes the supplied public lifecycle identity for a policy.
     *
     * The context and policy select the logical current/previous K4 namespace.
     * A stale, expired, mismatched, absent, or already-consumed identity
     * returns false; valid evidence returns true and cannot be claimed again.
     * Backend corruption or an unavailable required capability follows the
     * package exception/failure path.
     */
    public function claimPostPunishmentReentry(RateLimitContextDTO $context, string $policyName, string $reentryId): bool;
}
