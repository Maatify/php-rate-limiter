<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
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
}
