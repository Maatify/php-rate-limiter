<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The sole account derivation (DEC-046; bytes established by DEC-044, `docs/KEY_SCOPE_ENCODING_E1.md` §3): a length-prefixed preimage over opaque byte strings, an HMAC key domain of
 * its own, and the textual key form `e1.` followed by 64 lowercase hexadecimal characters.
 *
 * @internal
 */
final class E1AccountScopeKeyDeriver
{
    public const MAGIC = "maatify.rate-limiter.scope.e1\x00";
    public const KEY_DOMAIN = 'maatify.rate-limiter.key-domain.e1';
    public const KEY_PATTERN = '/\Ae1\.[0-9a-f]{64}\z/D';

    /** The largest length `u32be` represents. */
    private const MAX_FIELD_BYTES = 0xFFFFFFFF;

    /** @param \Closure(string): int $length The byte length of a field; a seam so the bound can be tested without a 2^32-byte string. */
    public function __construct(private readonly string $envScope, private readonly ?\Closure $length = null) {}

    /** Derive a registered purpose from opaque policy/environment/account bytes and its required material shape. */
    public function derive(string $purpose, string $policy, string $account, string $secret, ?string $material = null): string
    {
        AccountScopePurpose::assertShape($purpose, $material);
        $fields = [$purpose, $policy, $this->envScope, $account];
        if ($material !== null) {
            $fields[] = $material;
        }

        return self::key($secret, $fields, $this->length);
    }

    /**
     * The preimage of the given fields.
     *
     * @param list<string> $fields
     * @param ?\Closure(string): int $length
     * @throws RateLimiterException before any packing, HMAC or store command when a field is longer than 2^32 - 1 bytes
     */
    public static function preimage(array $fields, ?\Closure $length = null): string
    {
        $out = self::MAGIC . pack('N', count($fields));
        foreach ($fields as $field) {
            $bytes = $length === null ? strlen($field) : $length($field);
            if ($bytes > self::MAX_FIELD_BYTES) {
                throw new RateLimiterException('A field of an account-scope key is longer than 2^32 - 1 bytes.');
            }
            $out .= pack('N', $bytes) . $field;
        }

        return $out;
    }

    /**
     * @param list<string> $fields
     * @param ?\Closure(string): int $length
     */
    public static function key(string $secret, array $fields, ?\Closure $length = null): string
    {
        $preimage = self::preimage($fields, $length);
        $subkey = hash_hmac('sha256', self::KEY_DOMAIN, $secret, true);

        return 'e1.' . hash_hmac('sha256', $preimage, $subkey);
    }

    /** Whether a string has the textual form of an E1 key (a grammar test for logs and members; never a routing signal). */
    public static function hasKeyForm(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }
}
