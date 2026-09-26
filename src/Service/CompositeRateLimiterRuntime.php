<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;

/**
 * Delegating composition between the existing score runtime and the new
 * fixed-window simple runtime (DEC-010).
 *
 * This is the single composite runtime object returned by one
 * RateLimiterBuilder::build() call. It does not re-implement either
 * collaborator's behavior.
 */
final class CompositeRateLimiterRuntime implements CompositeRateLimiterRuntimeInterface
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

    public function consume(string $policyName, string $subject): SimpleRateLimitResultDTO
    {
        return $this->simpleRuntime->consume($policyName, $subject);
    }
}
