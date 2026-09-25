<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/**
 * Composite production runtime contract returned by the package builder.
 *
 * It preserves the normal rate-limit entrypoint and adds the public,
 * one-shot post-punishment re-entry claim without exposing storage keys or
 * backend lifecycle DTOs.
 */
interface RateLimiterRuntimeInterface extends RateLimiterInterface, PostPunishmentReentryClaimInterface
{
    /**
     * Consumes one public re-entry identity after the punishment is served.
     *
     * @return bool True only for the single successful claim; false for stale,
     * expired, mismatched, absent, or already-claimed evidence.
     */
    public function claimPostPunishmentReentry(RateLimitContextDTO $context, string $policyName, string $reentryId): bool;
}
