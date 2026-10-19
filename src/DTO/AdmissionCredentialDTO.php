<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Claims of an admission credential: the proof that a requester holds an account-bound credential for
 * one subject, a set of budget groups and one client instance. The Host decides when to issue one and
 * with which lifetime; the package only encodes and verifies the claims.
 */
final readonly class AdmissionCredentialDTO
{
    /** @param list<string> $budgetGroups */
    public function __construct(
        public string $subject,
        public array $budgetGroups,
        public string $instance,
        public string $jti,
        public int $expiresAt,
        public int $boundAt,
        public int $epoch,
        public ?string $keyId = null,
    ) {
        if (trim($subject) === '' || trim($instance) === '' || trim($jti) === '') {
            throw new RateLimiterException('Admission credential subject, instance and id must not be empty.');
        }
        if ($budgetGroups === []) {
            throw new RateLimiterException('Admission credential requires at least one budget group.');
        }
        foreach ($budgetGroups as $group) {
            // @phpstan-ignore-next-line function.alreadyNarrowedType
            if (! is_string($group) || trim($group) === '') {
                throw new RateLimiterException('Admission credential budget groups must be non-empty strings.');
            }
        }
        if ($expiresAt < 0 || $boundAt < 0 || $epoch < 0) {
            throw new RateLimiterException('Admission credential times and epoch must not be negative.');
        }
    }
}
