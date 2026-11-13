<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The admission budgets and the permit timing the admission runtime applies (DEC-025, LT-12).
 *
 * The values the supported composition applies are the package constants of `packageDefaults()` (DEC-028): the builder
 * constructs the admission runtime with them, and no Host input sets any of them. The constructor stays open to the
 * package's own verification harness; it is not part of the supported public surface.
 *
 * `provenPendingPermitCap` (#206) bounds the permits a source holds at once on the proven lane, from the reservation until
 * the outcome is claimed or the deadline `D` passes. `null` (the package default) sets no bound: that is the behaviour of the
 * library before the option existed, where only the recorded-failure cap, the per-account and per-proof counters apply.
 * It is independent of the failure sets: nothing recorded as a failure raises or lowers it, and it never counts as one.
 *
 * `permitDeadlineSeconds` (`D`) must not exceed the window (`D <= W`, security feasibility §2.3); `permitGraceSeconds` (`G`)
 * may be zero.
 *
 * @internal
 */
final readonly class AdmissionBudgetsDTO
{
    /**
     * The supported range of the proven pending-permit limit is 1 to this value (#206, DEC-038): the largest integer that Lua and
     * Redis represent exactly (2^53 - 1), which is also the largest scope capacity the Redis admission store accepts and the
     * largest value the registered shared configuration can hold. One constant for the builder, the DTOs and the store.
     */
    public const PROVEN_PENDING_PERMIT_LIMIT_MAX = 9007199254740991;

    public function __construct(
        public int $windowSeconds,
        public int $sourceCapacity,
        public int $sourceFailureCap,
        public int $accountBucketTakes,
        public int $accountBucketSeconds,
        public int $proofAccountWindow,
        public int $proofAccountDay,
        public int $proofProofWindow,
        public int $proofProofDay,
        public int $newDevicesPerDay,
        public int $crossAccountFailureCap,
        public int $permitDeadlineSeconds,
        public int $permitGraceSeconds,
        public ?int $provenPendingPermitCap = null,
    ) {
        $positive = [
            'window' => $windowSeconds,
            'source capacity' => $sourceCapacity,
            'source failure cap' => $sourceFailureCap,
            'account bucket takes' => $accountBucketTakes,
            'account bucket seconds' => $accountBucketSeconds,
            'proof account window' => $proofAccountWindow,
            'proof account day' => $proofAccountDay,
            'proof proof window' => $proofProofWindow,
            'proof proof day' => $proofProofDay,
            'new devices per day' => $newDevicesPerDay,
            'cross-account failure cap' => $crossAccountFailureCap,
            'permit deadline' => $permitDeadlineSeconds,
        ];
        foreach ($positive as $label => $value) {
            if ($value < 1) {
                throw new RateLimiterException("Admission budget {$label} must be a positive integer.");
            }
        }
        if ($provenPendingPermitCap !== null && ($provenPendingPermitCap < 1 || $provenPendingPermitCap > self::PROVEN_PENDING_PERMIT_LIMIT_MAX)) {
            throw new RateLimiterException('Admission proven-lane pending permit cap must be null or an integer from 1 to ' . self::PROVEN_PENDING_PERMIT_LIMIT_MAX . '.');
        }
        if ($permitGraceSeconds < 0) {
            throw new RateLimiterException('Admission permit grace must not be negative.');
        }
        if ($permitDeadlineSeconds > $windowSeconds) {
            throw new RateLimiterException('Admission permit deadline must not exceed the window (D <= W).');
        }
    }

    /**
     * The package constants of the admission runtime (DEC-028), the only place in the package that holds them:
     * window `W` 600 s, `U_src` 24 and the net failure cap `F` 4 (DEC-025 decision 1.4); the account bucket, `C` 4 takes
     * refilled over `T` 1800 s (2.2); `P_acct` 10 per window and 60 per day, `P_proof` 6 and 30, `N_P` 10 per day (3.1);
     * the cross-account proven-door failure cap 4 per source and window (8, as rule 8.2); the permit deadline `D` 30 s and
     * grace `G` 600 s (DEC-028). A different value is a new decision.
     */
    public static function packageDefaults(): self
    {
        return new self(
            windowSeconds: 600,
            sourceCapacity: 24,
            sourceFailureCap: 4,
            accountBucketTakes: 4,
            accountBucketSeconds: 1800,
            proofAccountWindow: 10,
            proofAccountDay: 60,
            proofProofWindow: 6,
            proofProofDay: 30,
            newDevicesPerDay: 10,
            crossAccountFailureCap: 4,
            permitDeadlineSeconds: 30,
            permitGraceSeconds: 600,
        );
    }

    /** A copy with the proven-lane pending permit cap set (`null` removes it). */
    public function withProvenPendingPermitCap(?int $cap): self
    {
        return new self(
            $this->windowSeconds,
            $this->sourceCapacity,
            $this->sourceFailureCap,
            $this->accountBucketTakes,
            $this->accountBucketSeconds,
            $this->proofAccountWindow,
            $this->proofAccountDay,
            $this->proofProofWindow,
            $this->proofProofDay,
            $this->newDevicesPerDay,
            $this->crossAccountFailureCap,
            $this->permitDeadlineSeconds,
            $this->permitGraceSeconds,
            $cap,
        );
    }
}
