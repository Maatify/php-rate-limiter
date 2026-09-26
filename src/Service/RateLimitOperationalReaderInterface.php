<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;

/**
 * Read-only contract for inspecting current and previous-generation state.
 */
interface RateLimitOperationalReaderInterface
{
    /**
     * Read one policy's operational snapshot without mutating limiter state.
     */
    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy,
    ): RateLimitOperationalSnapshotDTO;
}
