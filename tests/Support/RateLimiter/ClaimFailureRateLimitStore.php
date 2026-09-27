<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\SharedCommon\Contracts\ClockInterface;

final class ClaimFailureRateLimitStore extends InMemoryRateLimitStore
{
    public function __construct(
        ClockInterface $clock,
        private readonly \Throwable $failure,
    ) {
        parent::__construct($clock);
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        throw $this->failure;
    }
}
