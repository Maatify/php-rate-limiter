<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\FailureFallbackDimension;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Applies bounded in-process limits while the distributed backend is degraded.
 *
 * Counters are process-local and therefore provide a safety fallback, not a
 * replacement for the configured persistent store. This runtime applies
 * exactly the effective {@see FailureFallbackConfigurationDTO} a policy
 * declares through {@see FailureFallbackConfigurationProviderInterface};
 * package-owned official presets and host-owned direct custom
 * configurations share this same evaluation with no separate code path. A
 * policy without a valid bounded configuration receives no unbounded
 * degraded/fail-open allowance.
 */
class LocalFallbackLimiter
{
    /** @var array<string, array{count: int, expiresAt: int}> */
    private static array $counters = [];
    private static int $lastGc = 0;

    /**
     * Return whether the fallback window still permits the request.
     *
     * Each rule in the policy's effective configuration is evaluated
     * independently and namespaced by policy identity, so different reusable
     * policies never share process-local counters even when their numeric
     * values are identical.
     */
    public static function check(ClockInterface $clock, BlockPolicyInterface|string $policy, string $mode, string $ip, ?string $accountId = null, string $ua = ''): bool
    {
        $policy = self::normalizePolicy($policy);
        self::gc($clock);

        if ($mode !== 'DEGRADED_MODE' && $mode !== 'FAIL_OPEN') {
            return true;
        }

        $configuration = self::configuration($policy);
        if ($configuration === null || $configuration->rules === []) {
            return false;
        }

        $normalizedIp = self::getIpPrefix($ip);
        // Use the package canonical browser-major normalization for K2 parity.
        $normalizedUa = DeviceIdentityResolver::normalizeUserAgent($ua);
        $namespace = self::namespace($policy);

        $allowed = true;
        foreach ($configuration->rules as $rule) {
            $key = match ($rule->dimension) {
                FailureFallbackDimension::ACCOUNT => $accountId !== null && $accountId !== ''
                    ? "{$namespace}:account:{$accountId}"
                    : null,
                FailureFallbackDimension::IP_PREFIX => "{$namespace}:ip_prefix:{$normalizedIp}",
                FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT => "{$namespace}:ip_prefix_ua:" . md5("{$normalizedIp}:{$normalizedUa}"),
            };

            if ($key === null) {
                continue;
            }

            if (!self::incrementAndCheck($clock, $key, $rule->limit, $rule->windowSeconds)) {
                $allowed = false;
            }
        }

        return $allowed;
    }

    private static function configuration(BlockPolicyInterface $policy): ?FailureFallbackConfigurationDTO
    {
        return $policy instanceof FailureFallbackConfigurationProviderInterface
            ? $policy->getFailureFallbackConfiguration()
            : null;
    }

    private static function namespace(BlockPolicyInterface $policy): string
    {
        return 'fallback:' . hash('sha256', $policy->getName());
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
