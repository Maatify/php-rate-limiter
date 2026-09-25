<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\FailureFallbackProfile;
use Maatify\RateLimiter\Config\FailureFallbackProfileProviderInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Applies bounded in-process limits while the distributed backend is degraded.
 *
 * Counters are process-local and therefore provide a safety fallback, not a
 * replacement for the configured persistent store.
 */
class LocalFallbackLimiter
{
    /** @var array<string, array{count: int, expiresAt: int}> */
    private static array $counters = [];
    private static int $lastGc = 0;

    // Windows (Seconds)
    private const WINDOW_LOGIN = 600; // 10m
    private const WINDOW_OTP = 900;   // 15m
    private const WINDOW_API = 60;    // 1m

    // Caps
    private const DEGRADED_LOGIN_ACCOUNT = 3;
    private const DEGRADED_LOGIN_IP = 20;
    private const DEGRADED_OTP_ACCOUNT = 2;
    private const DEGRADED_OTP_IP = 10;
    private const API_IP = 120;
    private const API_IP_UA = 60;

    /**
     * Return whether the fallback window still permits the request.
     *
     * Login and OTP use account/IP caps in degraded mode. API protection also
     * applies IP/user-agent caps in degraded and fail-open modes.
     */
    public static function check(ClockInterface $clock, BlockPolicyInterface|string $policy, string $mode, string $ip, ?string $accountId = null, string $ua = ''): bool
    {
        $policy = self::normalizePolicy($policy);
        self::gc($clock);

        $allowed = true;

        // Normalize IP (IPv6 /64)
        $normalizedIp = self::getIpPrefix($ip);

        // Use the package canonical browser-major normalization for K2 parity.
        $normalizedUa = DeviceIdentityResolver::normalizeUserAgent($ua);

        $profile = self::profile($policy);
        if ($mode === 'DEGRADED_MODE') {
            if ($profile === null) {
                return false;
            }
            if ($profile === FailureFallbackProfile::AUTHENTICATION_PRIMARY
                || $profile === FailureFallbackProfile::AUTHENTICATION_STEP_UP) {
                $isOtp = $profile === FailureFallbackProfile::AUTHENTICATION_STEP_UP;
                $window = $isOtp ? self::WINDOW_OTP : self::WINDOW_LOGIN;
                $accountLimit = $isOtp ? self::DEGRADED_OTP_ACCOUNT : self::DEGRADED_LOGIN_ACCOUNT;
                $ipLimit = $isOtp ? self::DEGRADED_OTP_IP : self::DEGRADED_LOGIN_IP;
                $namespace = self::namespace($policy, $profile);
                if ($accountId && !self::incrementAndCheck($clock, "{$namespace}:acc:{$accountId}", $accountLimit, $window)) {
                    $allowed = false;
                }
                if (!self::incrementAndCheck($clock, "{$namespace}:ip:{$normalizedIp}", $ipLimit, $window)) {
                    $allowed = false;
                }
            } elseif ($profile === FailureFallbackProfile::API_OVERUSE) {
                $window = self::WINDOW_API;
                $namespace = self::namespace($policy, $profile);
                if (!self::incrementAndCheck($clock, "{$namespace}:ip:{$normalizedIp}", self::API_IP, $window)) {
                    $allowed = false;
                }
                $k2 = md5("{$normalizedIp}:{$normalizedUa}");
                if (!self::incrementAndCheck($clock, "{$namespace}:k2:{$k2}", self::API_IP_UA, $window)) {
                    $allowed = false;
                }
            }
        } elseif ($mode === 'FAIL_OPEN' && $profile === FailureFallbackProfile::API_OVERUSE) {
            $window = self::WINDOW_API;
            $namespace = self::namespace($policy, $profile);
            if (!self::incrementAndCheck($clock, "{$namespace}:ip:{$normalizedIp}", self::API_IP, $window)) {
                $allowed = false;
            }
            $k2 = md5("{$normalizedIp}:{$normalizedUa}");
            if (!self::incrementAndCheck($clock, "{$namespace}:k2:{$k2}", self::API_IP_UA, $window)) {
                $allowed = false;
            }
        }

        return $allowed;
    }

    private static function profile(BlockPolicyInterface $policy): ?FailureFallbackProfile
    {
        return $policy instanceof FailureFallbackProfileProviderInterface
            ? $policy->getFailureFallbackProfile()
            : null;
    }

    private static function namespace(BlockPolicyInterface $policy, ?FailureFallbackProfile $profile): string
    {
        $profileName = $profile === null ? 'NONE' : $profile->value;
        return 'fallback:' . hash('sha256', $policy->getName() . ':' . $profileName);
    }

    private static function normalizePolicy(BlockPolicyInterface|string $policy): BlockPolicyInterface
    {
        if ($policy instanceof BlockPolicyInterface) {
            return $policy;
        }

        return match ($policy) {
            'login_protection' => new LoginProtectionPolicy(),
            'otp_protection' => new OtpProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy(),
            default => throw new RateLimiterException("Unknown legacy fallback policy: {$policy}"),
        };
    }

    private static function getIpPrefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $hex = bin2hex($packed);
                return substr($hex, 0, 16); // /64
            }
        }
        return $ip;
    }

    private static function incrementAndCheck(ClockInterface $clock, string $key, int $limit, int $window): bool
    {
        // Use time bucket for stateless window tracking
        $bucket = (int) floor($clock->now()->getTimestamp() / $window);
        $bucketKey = "{$key}:{$bucket}";

        if (!isset(self::$counters[$bucketKey])) {
            self::$counters[$bucketKey] = [
                'count' => 0,
                'expiresAt' => ($bucket + 1) * $window,
            ];
        }
        self::$counters[$bucketKey]['count']++;
        return self::$counters[$bucketKey]['count'] <= $limit;
    }

    private static function gc(ClockInterface $clock): void
    {
        // Simple GC to prevent infinite array growth
        $now = $clock->now()->getTimestamp();
        if ($now - self::$lastGc > 3600) { // Every hour
            foreach (self::$counters as $bucketKey => $counter) {
                if ($counter['expiresAt'] <= $now) {
                    unset(self::$counters[$bucketKey]);
                }
            }
            self::$lastGc = $now;
        }
    }
}
