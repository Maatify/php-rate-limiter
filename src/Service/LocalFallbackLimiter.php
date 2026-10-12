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

    /**
     * Retry hint for a denial that has no fixed window to derive a reset from
     * (a degraded policy without a valid bounded configuration). It is the
     * historical value, kept unchanged so that denial is never relaxed.
     */
    private const UNWINDOWED_DENIAL_RETRY_AFTER = 60;

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
        return self::evaluate($clock, $policy, $mode, $ip, $accountId, $ua) === null;
    }

    /**
     * Evaluate every applicable rule once and return the Retry-After hint.
     *
     * Returns `null` when the request is permitted. When denied, returns the
     * largest remaining fixed-window time, in seconds, among the dimensions
     * that denied this same evaluation, assuming no further requests arrive.
     * It is a wait hint, not a guarantee that the next request is accepted.
     * Each rule's counter is consumed exactly once per evaluation, so callers
     * must use this method instead of {@see self::check()} followed by a
     * second query.
     *
     * @internal Runtime collaborator of {@see RateLimiterEngine}.
     *
     * @return int<1, max>|null
     */
    public static function evaluate(ClockInterface $clock, BlockPolicyInterface $policy, string $mode, string $ip, ?string $accountId = null, string $ua = ''): ?int
    {
        self::gc($clock);

        if ($mode !== 'DEGRADED_MODE' && $mode !== 'FAIL_OPEN') {
            return null;
        }

        $configuration = self::configuration($policy);
        if ($configuration === null || $configuration->rules === []) {
            return self::UNWINDOWED_DENIAL_RETRY_AFTER;
        }

        $normalizedIp = self::getIpPrefix($ip);
        // Use the package canonical browser-major normalization for K2 parity.
        $normalizedUa = DeviceIdentityResolver::normalizeUserAgent($ua);
        $namespace = self::namespace($policy);

        $retryAfter = null;
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
            $ruleRetryAfter = self::incrementAndCheck($clock, $population, $subject, $rule->limit, $rule->windowSeconds);
            if ($ruleRetryAfter !== null && ($retryAfter === null || $ruleRetryAfter > $retryAfter)) {
                $retryAfter = $ruleRetryAfter;
            }
        }

        return $retryAfter;
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

    /**
     * @return int<1, max>|null Remaining seconds of the denying window, or null when permitted.
     */
    private static function incrementAndCheck(ClockInterface $clock, string $population, string $subject, int $limit, int $window): ?int
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
        if (self::$counters[$bucketKey]['count'] <= $limit) {
            return null;
        }

        return max(1, self::$counters[$bucketKey]['expiresAt'] - $now);
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
