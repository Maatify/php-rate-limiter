<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Host-proven revocation of every admission credential issued before now for one account
 * (password change, recovery, log-out-everywhere). A separate interface so that the existing runtime
 * interfaces keep their implementers valid. Dormant: no implementation ships in this change.
 */
interface AdmissionBindingControlInterface
{
    /** Bump the account's binding epoch; every credential carrying an older epoch stops verifying. */
    public function revokeAccountBinding(string $accountId): void;
}
