<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;

/** Lets the real (final) operational reader be driven through the same test-side view as its mutant copies. */
final readonly class RealReaderAdapter implements LaneAwareReaderInterface
{
    public function __construct(private RateLimitOperationalReader $reader) {}

    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy,
        ?AdmissionLaneEnum $lane = null,
    ): RateLimitOperationalSnapshotDTO {
        return $this->reader->read($context, $policy, $lane);
    }
}
