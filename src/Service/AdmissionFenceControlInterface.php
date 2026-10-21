<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Operator control of the fleet fence of the admission runtime (contract M2; OD-7 of stage 5-c). The admission path of a
 * budget group is taken only while its fence is open; it is opened once every worker runs a version that reserves.
 * A separate interface so that existing implementers of the runtime interfaces stay valid. There is no package
 * operation that closes a fence: closing is an operator action on the store (`docs/guides/USAGE_GUIDE.md`).
 */
interface AdmissionFenceControlInterface
{
    /**
     * Open the fence of `$budgetGroup` (raise its value by one) and return the new value.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when no admission runtime is configured
     */
    public function openAdmissionFence(string $budgetGroup): int;
}
