<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The source of a request as the key strategy defines it: an IPv4 address as is, an IPv6 address by its leading hex digits
 * (a `/64` by default). One derivation for the pipeline (K1) and the admission runtime (source scopes).
 *
 * An IPv4-mapped IPv6 address (::ffff:0:0/96) is unwrapped to its embedded IPv4 address so that both representations
 * share the same source key and rate-limiting state.
 *
 * @internal
 */
final class SourcePrefix
{
    public static function of(string $ip, int $cidr = 64): string
    {
        $unwrapped = self::unwrapIpv4Mapped($ip);
        if ($unwrapped !== $ip) {
            return $unwrapped;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $hex = bin2hex($packed);
                $length = (int) ceil($cidr / 4);

                return substr($hex, 0, $length);
            }
        }

        return $ip;
    }

    /**
     * Unwraps an IPv4-mapped IPv6 address (::ffff:0:0/96) to its standard dot-decimal IPv4 address.
     * Non-mapped addresses (pure IPv6, IPv4, invalid) are returned unchanged.
     */
    public static function unwrapIpv4Mapped(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                $ipv4 = inet_ntop(substr($packed, 12, 4));
                if ($ipv4 !== false) {
                    return $ipv4;
                }
            }
        }

        return $ip;
    }

    /**
     * Returns true if the IP address is a pure IPv6 address (not IPv4-mapped).
     */
    public static function isPureIpv6(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return strlen($packed) !== 16 || ! str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff");
    }
}
