<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;

/**
 * Additive bounded capability preserving one coordinated previous generation.
 */
interface BoundedCorrelationRotationStoreInterface extends BoundedCorrelationStoreInterface, CorrelationRotationStoreInterface
{
    /**
     * Atomically observe a member across current, bridge, and read-only previous state.
     */
    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO;
}
