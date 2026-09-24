<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

interface PostPunishmentReentryClaimInterface
{
    public function claimPostPunishmentReentry(RateLimitContextDTO $context, string $policyName, string $reentryId): bool;
}
