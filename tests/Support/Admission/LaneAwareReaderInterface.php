<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;

/**
 * Test-side view of the operational reader's optional lane (5b3a/PLAN.md D12). The public reader interface is
 * unchanged; mutant copies of the reader are declared to implement this view so the detectors can call them.
 */
interface LaneAwareReaderInterface
{
    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy,
        ?AdmissionLaneEnum $lane = null,
    ): RateLimitOperationalSnapshotDTO;
}
