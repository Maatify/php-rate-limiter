<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;

interface RateLimitOperationalReaderInterface
{
    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy
    ): RateLimitOperationalSnapshotDTO;
}
