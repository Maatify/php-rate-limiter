<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Canonical non-account key derivation for K1–K3, correlation and adaptive IPv6 families (DEC-047).
 *
 * Preimage wire format:
 *   `MAGIC || u32be(N) || u32be(strlen(f_1)) || f_1 || ... || u32be(strlen(f_N)) || f_N`
 * where `MAGIC = "maatify.rate-limiter.non-account.f1\x00"`, `f_1 = purpose`, `f_2 = policy`, `f_3 = env`,
 * followed by the registered semantic fields in canonical order.
 *
 * Domain-separated HMAC-SHA256:
 *   `subkey = HMAC-SHA256(key = secret, data = "maatify.rate-limiter.key-domain.non-account.f1", raw = true)`
 *   `key    = lowercase-hex(HMAC-SHA256(key = subkey, data = preimage))`
 *
 * Shared by both `EvaluationPipeline` and `RateLimitOperationalReader` as one immutable implementation.
 *
 * @internal
 */
final class NonAccountScopeKeyDeriver
{
    public const MAGIC = "maatify.rate-limiter.non-account.f1\x00";
    public const KEY_DOMAIN = 'maatify.rate-limiter.key-domain.non-account.f1';

    /**
     * @param ?\Closure(string): int $length Internal boundary seam for testing field lengths without huge allocations.
     * @param ?\Closure(list<string>): int $count Internal boundary seam for testing field counts without huge allocations.
     */
    public function __construct(
        private readonly string $envScope,
        private readonly ?\Closure $length = null,
        private readonly ?\Closure $count = null,
    ) {}

    /**
     * Derive a registered non-account key from opaque policy, environment and semantic field bytes.
     *
     * @throws RateLimiterException when `$purpose` is unknown, the semantic arity mismatches, or a length/count exceeds `u32be`.
     */
    public function derive(
        string $purpose,
        string $policy,
        #[\SensitiveParameter]
        string $secret,
        string ...$semanticFields,
    ): string {
        $fields = array_values($semanticFields);
        NonAccountScopePurpose::assertShape($purpose, $fields);

        return self::key(
            $secret,
            [$purpose, $policy, $this->envScope, ...$fields],
            $this->length,
            $this->count,
        );
    }

    public function deriveK1(string $policy, string $ipPrefix, #[\SensitiveParameter] string $secret): string
    {
        return $this->derive(NonAccountScopePurpose::K1, $policy, $secret, $ipPrefix);
    }

    public function deriveK2(string $policy, string $ipPrefix, string $normalizedUa, #[\SensitiveParameter] string $secret): string
    {
        return $this->derive(NonAccountScopePurpose::K2, $policy, $secret, $ipPrefix, $normalizedUa);
    }

    public function deriveK3(string $policy, string $ipPrefix, string $fingerprintHash, #[\SensitiveParameter] string $secret): string
    {
        return $this->derive(NonAccountScopePurpose::K3, $policy, $secret, $ipPrefix, $fingerprintHash);
    }

    /**
     * Build the checked length-prefixed preimage for the given field tuple.
     *
     * @param list<string> $fields
     * @param ?\Closure(string): int $length
     * @param ?\Closure(list<string>): int $count
     * @throws RateLimiterException before packing or HMAC when a field length or count is outside `0..4294967295`.
     */
    public static function preimage(array $fields, ?\Closure $length = null, ?\Closure $count = null): string
    {
        return KeyPreimageFraming::encodeTuple(self::MAGIC, $fields, 'non-account key', $length, $count);
    }

    /**
     * Derive the 64-character lowercase hex digest for a field tuple under the non-account HMAC domain.
     *
     * @param list<string> $fields
     * @param ?\Closure(string): int $length
     * @param ?\Closure(list<string>): int $count
     * @throws RateLimiterException before packing or HMAC when a field length or count is outside `0..4294967295`.
     */
    public static function key(
        #[\SensitiveParameter]
        string $secret,
        array $fields,
        ?\Closure $length = null,
        ?\Closure $count = null,
    ): string {
        $preimage = self::preimage($fields, $length, $count);
        $subkey = hash_hmac('sha256', self::KEY_DOMAIN, $secret, true);

        return hash_hmac('sha256', $preimage, $subkey);
    }
}
