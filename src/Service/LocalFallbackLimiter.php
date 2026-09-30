<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
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
    private const MAX_TRACKED_SUBJECTS = 4096;

    /** @var array<string, array{count: int, expiresAt: int}> */
    private static array $counters = [];

    /** @var array<string, array<string, true>> */
    private static array $trackedSubjects = [];

    /** @var array<string, int> */
    private static array $trackedSubjectExpiries = [];

    /**
     * Return whether the fallback window still permits the request.
     *
     * Each rule in the policy's effective configuration is evaluated
     * independently and namespaced by policy identity, so different reusable
     * policies never share process-local counters even when their numeric
     * values are identical.
     */
    public static function check(ClockInterface $clock, BlockPolicyInterface $policy, string $mode, string $ip, ?string $accountId = null, string $ua = ''): bool
    {
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
                FailureFallbackDimensionEnum::ACCOUNT => $accountId !== null && $accountId !== ''
                    ? "{$namespace}:account:{$accountId}"
                    : null,
                FailureFallbackDimensionEnum::IP_PREFIX => "{$namespace}:ip_prefix:{$normalizedIp}",
                FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT => "{$namespace}:ip_prefix_ua:" . md5("{$normalizedIp}:{$normalizedUa}"),
            };

            if ($key === null) {
                continue;
            }

            $population = "{$namespace}:{$rule->dimension->value}";
            $subject = $key;
            if (!self::incrementAndCheck($clock, $population, $subject, $rule->limit, $rule->windowSeconds)) {
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

    private static function incrementAndCheck(ClockInterface $clock, string $population, string $subject, int $limit, int $window): bool
    {
        $now = $clock->now()->getTimestamp();
        $bucket = (int) floor($now / $window);
        $populationKey = "{$population}:{$bucket}";
        $tracked = self::$trackedSubjects[$populationKey] ?? [];
        if (isset($tracked[$subject])) {
            $bucketKey = "{$populationKey}:subject:" . hash('sha256', $subject);
        } elseif (count($tracked) < self::MAX_TRACKED_SUBJECTS) {
            $tracked[$subject] = true;
            self::$trackedSubjects[$populationKey] = $tracked;
            self::$trackedSubjectExpiries[$populationKey] = ($bucket + 1) * $window;
            $bucketKey = "{$populationKey}:subject:" . hash('sha256', $subject);
        } else {
            $bucketKey = "{$populationKey}:overflow";
        }

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
        $now = $clock->now()->getTimestamp();
        foreach (self::$counters as $bucketKey => $counter) {
            if ($counter['expiresAt'] <= $now) {
                unset(self::$counters[$bucketKey]);
            }
        }
        foreach (self::$trackedSubjects as $populationKey => $_subjects) {
            if ((self::$trackedSubjectExpiries[$populationKey] ?? 0) <= $now) {
                unset(self::$trackedSubjects[$populationKey]);
                unset(self::$trackedSubjectExpiries[$populationKey]);
            }
        }
    }
}
