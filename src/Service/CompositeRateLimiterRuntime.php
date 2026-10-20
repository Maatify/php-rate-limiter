<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Delegating composition between the existing score runtime and the new
 * fixed-window simple runtime (DEC-010).
 *
 * This is the single composite runtime object returned by one
 * RateLimiterBuilder::build() call. It does not re-implement either
 * collaborator's behavior.
 */
final class CompositeRateLimiterRuntime implements CompositeRateLimiterRuntimeInterface, AdmissionBindingControlInterface, AdmissionBindingEpochInterface
{
    public function __construct(
        private readonly RateLimiterRuntimeInterface $scoreRuntime,
        private readonly SimpleRateLimiterInterface $simpleRuntime,
    ) {}

    public function limit(RateLimitContextDTO $context, RateLimitCommand $request): RateLimitResultDTO
    {
        return $this->scoreRuntime->limit($context, $request);
    }

    public function claimPostPunishmentReentry(RateLimitContextDTO $context, string $policyName, string $reentryId): bool
    {
        return $this->scoreRuntime->claimPostPunishmentReentry($context, $policyName, $reentryId);
    }

    /** Reaches the score runtime's binding control (S8); a runtime without one cannot revoke. */
    public function revokeAccountBinding(string $accountId): void
    {
        if (! $this->scoreRuntime instanceof AdmissionBindingControlInterface) {
            throw new RateLimiterException('The configured score runtime has no admission binding control.');
        }
        $this->scoreRuntime->revokeAccountBinding($accountId);
    }

    /** Reaches the score runtime's binding epoch read (LT-21). */
    public function bindingEpoch(string $accountId): int
    {
        if (! $this->scoreRuntime instanceof AdmissionBindingEpochInterface) {
            throw new RateLimiterException('The configured score runtime has no admission binding epoch.');
        }

        return $this->scoreRuntime->bindingEpoch($accountId);
    }

    public function consume(string $policyName, string $subject, int $cost = 1): SimpleRateLimitResultDTO
    {
        return $this->simpleRuntime->consume($policyName, $subject, $cost);
    }
}
