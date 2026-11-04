<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/**
 * The one definition of account presence in the package (#185, F-185-03): an account is present when its identifier is
 * a non-empty string, `"0"` included. `null` and `""` are both absence: no account scope (K4, K5, the account budget,
 * the account correlation and auxiliary state, the account fallback dimension) is derived for them. Every check of
 * account presence goes through this class; the keys derived for a present identifier are unchanged.
 *
 * @internal
 */
final class AccountPresence
{
    /** @phpstan-assert-if-true non-empty-string $accountId */
    public static function isPresent(?string $accountId): bool
    {
        return $accountId !== null && $accountId !== '';
    }

    /** @phpstan-assert-if-true non-empty-string $context->accountId */
    public static function of(RateLimitContextDTO $context): bool
    {
        return self::isPresent($context->accountId);
    }

    /** The identifier when present, else `null`: for `correlationId ?? account` subjects. */
    public static function idOf(RateLimitContextDTO $context): ?string
    {
        return self::isPresent($context->accountId) ? $context->accountId : null;
    }
}
