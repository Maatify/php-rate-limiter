<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The admission budgets and the permit timing the admission runtime applies (DEC-025, LT-12).
 *
 * The package holds no value of its own: the caller constructs this object. It is not Host-configurable in the supported
 * composition (the activation change builds it from package constants in the builder), and it is not part of the supported
 * public surface until then.
 *
 * `permitDeadlineSeconds` (`D`) must not exceed the window (`D <= W`, security feasibility §2.3); `permitGraceSeconds` (`G`)
 * may be zero.
 *
 * @internal
 */
final readonly class AdmissionBudgetsDTO
{
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
        if ($permitGraceSeconds < 0) {
            throw new RateLimiterException('Admission permit grace must not be negative.');
        }
        if ($permitDeadlineSeconds > $windowSeconds) {
            throw new RateLimiterException('Admission permit deadline must not exceed the window (D <= W).');
        }
    }
}
