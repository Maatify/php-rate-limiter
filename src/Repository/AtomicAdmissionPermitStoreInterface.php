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
    /**
     * Claim the permit named by `$outcome->permitId` and apply `$outcome` to its scopes as one atomic operation,
     * judged at `$now` (Unix seconds, non-negative).
     *
     * The permit id is required and, like `$lane`, `$subject` and `$operation`, may not be empty. The claim results
     * mean what they mean for `AdmissionPermitStoreInterface::claimAdmissionPermit()`. Only `ACCEPTED` writes
     * anything: the permit becomes claimed and every scope is written or refunded together. Any other result leaves
     * the permit and the scopes unchanged.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when the permit id or an identifier is empty, `$now`
     *         is negative or not representable, a scope is not valid for an outcome, or stored state is malformed;
     *         it is raised before any write and the permit stays issued
     */
    public function claimAdmissionPermitWithOutcome(
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeDTO $outcome,
        int $now,
    ): AdmissionPermitClaimEnum;
}
