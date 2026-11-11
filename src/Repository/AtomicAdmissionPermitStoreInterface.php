<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;

/**
 * Atomic outcome claim and state mutation capability (DEC-036).
 *
 * Atomically claims an admission permit and applies all outcome scopes in a single operation:
 * - Validates all keys, types, and canonical scores before any write.
 * - If permit claim is not ACCEPTED (e.g. duplicate, late, unknown, conflict, rejected), nothing is written.
 * - If any scope has malformed state, an exception is thrown while the permit remains ISSUED.
 * - On ACCEPTED, atomically marks the permit CLAIMED and writes/refunds all outcome scopes.
 */
interface AtomicAdmissionPermitStoreInterface extends AdmissionPermitStoreInterface
{
    public function claimAdmissionPermitWithOutcome(
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeDTO $outcome,
        int $now,
    ): AdmissionPermitClaimEnum;
}
