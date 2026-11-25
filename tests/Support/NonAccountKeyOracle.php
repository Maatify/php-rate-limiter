<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support;

/**
 * Independent test oracle for the DEC-047 canonical non-account key framing (`f1`).
 *
 * Implements the wire contract directly without delegating to production `NonAccountScopeKeyDeriver`, so tests
 * pre-seeding or asserting K1–K3, correlation and IPv6 adaptive keys verify the runtime independently.
 */
final class NonAccountKeyOracle
{
    private const MAGIC = "maatify.rate-limiter.non-account.f1\x00";
    private const KEY_DOMAIN = 'maatify.rate-limiter.key-domain.non-account.f1';

    private function __construct() {}

    /**
     * @param list<string> $fields Full tuple `[purpose, policy, env, ...semanticFields]`.
     */
    public static function preimage(array $fields): string
    {
        $out = self::MAGIC . pack('N', count($fields));
        foreach ($fields as $field) {
            $out .= pack('N', strlen($field)) . $field;
        }

        return $out;
    }

    public static function derive(
        string $purpose,
        string $policy,
        string $env,
        string $secret,
        string ...$semanticFields,
    ): string {
        $preimage = self::preimage([$purpose, $policy, $env, ...array_values($semanticFields)]);
        $subkey = hash_hmac('sha256', self::KEY_DOMAIN, $secret, true);

        return hash_hmac('sha256', $preimage, $subkey);
    }

    public static function k1(string $policy, string $env, string $ipPrefix, string $secret): string
    {
        return self::derive('k1', $policy, $env, $secret, $ipPrefix);
    }

    public static function k2(string $policy, string $env, string $ipPrefix, string $ua, string $secret): string
    {
        return self::derive('k2', $policy, $env, $secret, $ipPrefix, $ua);
    }

    public static function k3(string $policy, string $env, string $ipPrefix, string $fpHash, string $secret): string
    {
        return self::derive('k3', $policy, $env, $secret, $ipPrefix, $fpHash);
    }
}
