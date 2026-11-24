<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Enum\KeyScopeEncodingEnum;

/**
 * The single derivation source of the account-bearing logical keys (DEC-044, E1 contract §4). The writer, the operational reader and every
 * future reader obtain Current and Previous keys from one instance; Previous is the same call with the previous secret and material.
 *
 * @internal
 */
interface AccountScopeKeyDeriverInterface
{
    public function encoding(): KeyScopeEncodingEnum;

    /**
     * @param string $purpose A token of {@see AccountScopePurpose}.
     * @param string $material Required exactly for the purposes that have one ({@see AccountScopePurpose::hasMaterial()}).
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException For an unknown purpose, a missing or unexpected material, or a field longer than 2^32 - 1 bytes.
     */
    public function derive(string $purpose, string $policy, string $account, string $secret, ?string $material = null): string;
}
