<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Read side of the account binding epoch: the number a Host embeds in a credential it issues (and re-reads after
 * `AdmissionBindingControlInterface::revokeAccountBinding()`). A separate interface so that existing implementers of the
 * runtime interfaces stay valid. Dormant until the admission runtime is activated.
 */
interface AdmissionBindingEpochInterface
{
    /** The account's current binding epoch (0 when it was never revoked). */
    public function bindingEpoch(string $accountId): int;
}
