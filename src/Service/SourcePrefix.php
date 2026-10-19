<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The source of a request as the key strategy defines it: an IPv4 address as is, an IPv6 address by its leading hex digits
 * (a `/64` by default). One derivation for the pipeline (K1) and the admission runtime (source scopes).
 *
 * @internal
 */
final class SourcePrefix
{
    public static function of(string $ip, int $cidr = 64): string
    {
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
}
