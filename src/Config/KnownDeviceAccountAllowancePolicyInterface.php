<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Opts an authentication policy into a fingerprint-independent account
 * allowance for known-device failures.
 *
 * Implementing policies are authentication policies: every authentication
 * prerequisite applies and API_OVERUSE cannot be combined with them. Known-device
 * failures (trusted session or previously verified device) are scored on K5.
 * When the allowance is active, every such failure also consumes one unit of a
 * fixed 24-hour account epoch, and each failure beyond the allowance also adds
 * the policy's K4 failure delta. This is a per-failure K4 scoring rule, not an
 * absolute cap on verifier reaches: K4 score protection decays, depends on
 * recorded outcomes, and pre-checks do not reserve attempts.
 *
 * When the allowance is active (not null), the runtime rejects a non-positive
 * allowance, a non-positive K5 failure delta, and missing or non-monotonic K5
 * thresholds.
 */
interface KnownDeviceAccountAllowancePolicyInterface extends BlockPolicyInterface
{
    /**
     * Known-device failures per account and fixed 24-hour epoch that are scored
     * on K5 only, or null when the allowance is disabled.
     */
    public function getKnownDeviceAccountAllowance(): ?int;
}
