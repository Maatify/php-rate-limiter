<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Opts an authentication policy into a fingerprint-independent account
 * allowance for known-device failures.
 *
 * Known-device failures (trusted session or previously verified device) are
 * normally scored on K5 only. With this opt-in, every such failure also
 * consumes one unit of a fixed 24-hour account epoch. Failures beyond the
 * allowance are additionally scored on K4 with the policy's K4 failure delta,
 * so a known device is never bounded more weakly than an unknown device once
 * the allowance is spent. The runtime rejects a non-positive allowance and a
 * policy without positive, monotonic K4 thresholds or a positive K4 failure
 * delta.
 */
interface KnownDeviceAccountAllowancePolicyInterface extends BlockPolicyInterface
{
    /**
     * Number of known-device failures per account and fixed 24-hour epoch that
     * are scored on K5 only.
     */
    public function getKnownDeviceAccountAllowance(): int;
}
