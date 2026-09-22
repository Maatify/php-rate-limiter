<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Validates host-store results before bounded correlation decisions consume them.
 */
final class BoundedCorrelationResultValidator
{
    /**
     * @return int The validated effective logical distinct count.
     */
    public static function count(BoundedDistinctResultDTO $result, int $maxDistinct): int
    {
        if ($maxDistinct < 1
            || $result->count < 1
            || $result->count > $maxDistinct
            || (! $result->accepted && $result->count !== $maxDistinct)) {
            throw new RateLimiterException('Bounded correlation store returned an invalid distinct result.');
        }

        return $result->count;
    }

    /**
     * Validate a bounded snapshot before a decision consumes its capability.
     *
     * The optional member arguments let callers enforce the duplicate contract
     * without making the validator aware of a particular correlation purpose.
     * A rotation-aware caller supplies both current and previous representations.
     */
    public static function snapshot(
        BoundedDistinctSnapshotDTO $result,
        int $maxDistinct,
        int $now,
        int $ttlSeconds,
        ?string $currentMember = null,
        ?string $previousMember = null,
    ): BoundedDistinctSnapshotDTO {
        if ($maxDistinct < 1 || $ttlSeconds < 1) {
            throw new RateLimiterException('Bounded correlation snapshot arguments must be positive.');
        }

        /** @var array<array-key, mixed> $members */
        $members = (array) $result->members;
        $isList = array_keys($members) === range(0, count($members) - 1);

        if (! $isList
            || $result->count < 1
            || $result->count > $maxDistinct
            || $result->count !== count($members)
            || $result->expiresAt <= $now
            || $result->expiresAt > $now + $ttlSeconds
            || (! $result->accepted && ($result->added || $result->count !== $maxDistinct))
            || ($result->added && ! $result->accepted)) {
            throw new RateLimiterException('Bounded correlation store returned an invalid snapshot.');
        }

        foreach ($members as $member) {
            if (! is_string($member) || $member === '') {
                throw new RateLimiterException('Bounded correlation snapshot members must be non-empty strings.');
            }
        }

        if (count(array_unique($members, SORT_STRING)) !== count($members)) {
            throw new RateLimiterException('Bounded correlation snapshot members must be unique.');
        }

        $currentRepresented = $currentMember !== null && in_array($currentMember, $result->members, true);
        $previousRepresented = $previousMember !== null && in_array($previousMember, $result->members, true);
        $sameLogicalMember = $currentMember !== null
            && $previousMember !== null
            && $currentMember === $previousMember;

        if (! $result->accepted && ($currentRepresented || $previousRepresented)) {
            throw new RateLimiterException('Rejected bounded correlation snapshot represented its observed member.');
        }

        if ($result->added) {
            if (! $currentRepresented) {
                throw new RateLimiterException('Bounded correlation snapshot omitted its admitted member.');
            }
            if ($previousRepresented && ! $sameLogicalMember) {
                throw new RateLimiterException('Added bounded correlation snapshot double-represented its member.');
            }
        }

        if ($result->accepted && ! $result->added && $currentMember !== null) {
            $representedOnce = $currentRepresented || $previousRepresented;
            $representedTwice = $currentRepresented && $previousRepresented && ! $sameLogicalMember;
            if (! $representedOnce || $representedTwice) {
                throw new RateLimiterException('Known bounded correlation snapshot has invalid logical membership.');
            }
        }

        return $result;
    }
}
