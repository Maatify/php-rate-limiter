<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/**
 * The one definition of presence for an identity string in the package (#185, F-185-03, F-185-04): a value is present
 * when it is a non-empty string, `"0"` included; `null` and `""` are both absence. It governs `accountId`,
 * `correlationId` and `fingerprintHash` (and `sessionDeviceId`, already read as `!== ''`). Every presence check of these
 * values goes through this class; the keys derived for a present value are unchanged.
 *
 * @internal
 */
final class IdentityPresence
{
    /** @phpstan-assert-if-true non-empty-string $value */
    public static function isPresent(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Whether the request names an account. No account scope (K4, K5, the account budget, the account correlation and
     * auxiliary state, the account fallback dimension) is derived for an absent one.
     *
     * @phpstan-assert-if-true non-empty-string $context->accountId
     */
    public static function account(RateLimitContextDTO $context): bool
    {
        return self::isPresent($context->accountId);
    }

    /** The account identifier when present, else `null`. */
    public static function accountId(RateLimitContextDTO $context): ?string
    {
        return self::isPresent($context->accountId) ? $context->accountId : null;
    }

    /** The correlation subject: `correlationId` when present, else the account identifier when present, else `null`. */
    public static function subject(RateLimitContextDTO $context): ?string
    {
        return self::isPresent($context->correlationId) ? $context->correlationId : self::accountId($context);
    }

    /**
     * Whether the device carries a current fingerprint. No K3 / K5 key and no fingerprint state is derived for an absent
     * one.
     *
     * @phpstan-assert-if-true non-empty-string $device->fingerprintHash
     */
    public static function fingerprint(DeviceIdentityDTO $device): bool
    {
        return self::isPresent($device->fingerprintHash);
    }
}
