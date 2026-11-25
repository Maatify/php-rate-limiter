<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Shared internal key derivation for simple fixed-window rate limiting (DEC-012, DEC-013, DEC-047).
 *
 * Consolidates writer (`FixedWindowSimpleRateLimiter`) and operational reader (`SimpleRateLimitOperationalReader`)
 * onto one immutable implementation while preserving the exact existing preimage layout and HMAC output byte-for-byte
 * and enforcing the checked `u32be` length bound before packing.
 *
 * @internal
 */
final class SimpleFixedWindowKeyDeriver
{
    /**
     * @param ?\Closure(string): int $length Internal boundary seam for testing field lengths without huge allocations.
     */
    public function __construct(
        private readonly string $environmentScope,
        private readonly ?\Closure $length = null,
    ) {}

    /**
     * Derive the simple fixed-window storage key using the established length-prefixed component order.
     *
     * @throws RateLimiterException before packing or HMAC when any component byte length is outside `0..4294967295`.
     */
    public function derive(
        string $policyName,
        int $limit,
        int $intervalSeconds,
        string $subject,
        #[\SensitiveParameter]
        string $secret,
    ): string {
        $preimage = self::preimage(
            $this->environmentScope,
            $policyName,
            $limit,
            $intervalSeconds,
            $subject,
            $this->length,
        );

        return hash_hmac('sha256', $preimage, $secret);
    }

    /**
     * Build the exact existing length-prefixed preimage without extra magic or count headers.
     *
     * @param ?\Closure(string): int $length
     * @throws RateLimiterException before packing when any component byte length is outside `0..4294967295`.
     */
    public static function preimage(
        string $environmentScope,
        string $policyName,
        int $limit,
        int $intervalSeconds,
        string $subject,
        ?\Closure $length = null,
    ): string {
        $fields = [
            'rate_limiter',
            'simple_fixed_window',
            'v1',
            $environmentScope,
            $policyName,
            (string) $limit,
            (string) $intervalSeconds,
            $subject,
        ];

        return KeyPreimageFraming::encodeFields($fields, 'simple fixed-window key', $length);
    }
}
