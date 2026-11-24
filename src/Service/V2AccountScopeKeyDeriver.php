<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Enum\KeyScopeEncodingEnum;

/**
 * The shipped derivation (V2): the same preimage text and the same plain HMAC as before DEC-044, moved behind the single derivation
 * source. Its output is byte-identical to the derivation it replaced.
 *
 * @internal
 */
final class V2AccountScopeKeyDeriver implements AccountScopeKeyDeriverInterface
{
    public function __construct(private readonly string $envScope) {}

    public function encoding(): KeyScopeEncodingEnum
    {
        return KeyScopeEncodingEnum::V2;
    }

    public function derive(string $purpose, string $policy, string $account, string $secret, ?string $material = null): string
    {
        AccountScopePurpose::assertShape($purpose, $material);
        $base = "{$policy}:rate_limiter";
        $env = $this->envScope;
        $tail = $material === null ? '' : ":{$material}";
        $preimage = match (true) {
            $purpose === AccountScopePurpose::K4, $purpose === AccountScopePurpose::K4P => "{$base}:{$purpose}:v2:{$env}:{$account}",
            $purpose === AccountScopePurpose::K5, $purpose === AccountScopePurpose::K5P => "{$base}:{$purpose}:v2:{$env}:{$account}{$tail}",
            $purpose === AccountScopePurpose::MICROCAP_K5 => "{$base}:microcap:k5:v1:{$env}:{$account}{$tail}",
            $purpose === AccountScopePurpose::MICROCAP_K5P => "{$base}:microcap:k5p:v1:{$env}:{$account}{$tail}",
            $purpose === AccountScopePurpose::BUDGET_COOLDOWN, $purpose === AccountScopePurpose::BUDGET_COOLDOWN_P => "{$base}:{$purpose}:v1:{$env}:{$account}",
            default => "{$base}:aux:" . substr($purpose, 4) . ":v1:{$env}:{$account}{$tail}",
        };

        return hash_hmac('sha256', $preimage, $secret);
    }
}
