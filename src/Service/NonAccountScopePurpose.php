<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Closed internal purpose registry for canonical non-account key derivation (DEC-047).
 *
 * Every registered purpose fixes its exact arity (fields following `[purpose, policy, env]`) and separates family
 * and role (`scope`, `member`, `bridge`, `watch`, `window`, `confirmation`, `churn_anchor`). Unregistered purposes
 * and arity mismatches are refused before framing or HMAC.
 *
 * @internal
 */
final class NonAccountScopePurpose
{
    public const K1 = 'k1';
    public const K2 = 'k2';
    public const K3 = 'k3';

    public const CORRELATION_DEVICE_CAP_IP_SCOPE = 'correlation.device_cap_ip.scope';
    public const CORRELATION_DEVICE_CAP_IP_MEMBER = 'correlation.device_cap_ip.member';
    public const CORRELATION_DEVICE_CAP_IP_BRIDGE = 'correlation.device_cap_ip.bridge';

    public const CORRELATION_DEVICE_CAP_ACCOUNT_SCOPE = 'correlation.device_cap_account.scope';
    public const CORRELATION_DEVICE_CAP_ACCOUNT_MEMBER = 'correlation.device_cap_account.member';
    public const CORRELATION_DEVICE_CAP_ACCOUNT_BRIDGE = 'correlation.device_cap_account.bridge';

    public const CORRELATION_CHURN_SCOPE = 'correlation.churn.scope';
    public const CORRELATION_CHURN_MEMBER = 'correlation.churn.member';
    public const CORRELATION_CHURN_BRIDGE = 'correlation.churn.bridge';
    public const CORRELATION_CHURN_WATCH = 'correlation.churn_watch';

    public const CORRELATION_DILUTION_SCOPE = 'correlation.dilution.scope';
    public const CORRELATION_DILUTION_MEMBER = 'correlation.dilution.member';
    public const CORRELATION_DILUTION_BRIDGE = 'correlation.dilution.bridge';
    public const CORRELATION_DILUTION_WATCH = 'correlation.dilution_watch';
    public const CORRELATION_DILUTION_CONFIRMATION = 'correlation.dilution_confirmation';

    public const CORRELATION_DISTRIBUTED_DEVICES_SCOPE = 'correlation.distributed_account_devices.scope';
    public const CORRELATION_DISTRIBUTED_DEVICES_BRIDGE = 'correlation.distributed_account_devices.bridge';
    public const CORRELATION_DISTRIBUTED_WATCH = 'correlation.distributed_account_watch';

    public const CORRELATION_DISTRIBUTED_OCCURRENCES_SCOPE = 'correlation.distributed_account_occurrences.scope';
    public const CORRELATION_DISTRIBUTED_OCCURRENCES_WINDOW = 'correlation.distributed_account_occurrences.window';
    public const CORRELATION_DISTRIBUTED_OCCURRENCES_BRIDGE = 'correlation.distributed_account_occurrences.bridge';

    public const IPV6_ADAPTIVE_HIERARCHY_SCOPE = 'ipv6_adaptive.hierarchy.scope';
    public const IPV6_ADAPTIVE_HIERARCHY_BRIDGE = 'ipv6_adaptive.hierarchy.bridge';
    public const IPV6_ADAPTIVE_CHURN_ANCHOR = 'ipv6_adaptive.churn_anchor';

    public const IPV6_ADAPTIVE_CHURN_SCOPE = 'ipv6_adaptive.churn.scope';
    public const IPV6_ADAPTIVE_CHURN_MEMBER = 'ipv6_adaptive.churn.member';
    public const IPV6_ADAPTIVE_CHURN_BRIDGE = 'ipv6_adaptive.churn.bridge';

    public const IPV6_ADAPTIVE_SPRAY_SCOPE = 'ipv6_adaptive.spray.scope';
    public const IPV6_ADAPTIVE_SPRAY_MEMBER = 'ipv6_adaptive.spray.member';
    public const IPV6_ADAPTIVE_SPRAY_BRIDGE = 'ipv6_adaptive.spray.bridge';

    /**
     * Registered purpose => number of semantic fields after `[purpose, policy, env]`.
     *
     * @var array<string, int>
     */
    private const SEMANTIC_ARITY = [
        self::K1 => 1,
        self::K2 => 2,
        self::K3 => 2,

        self::CORRELATION_DEVICE_CAP_IP_SCOPE => 1,
        self::CORRELATION_DEVICE_CAP_IP_MEMBER => 1,
        self::CORRELATION_DEVICE_CAP_IP_BRIDGE => 1,

        self::CORRELATION_DEVICE_CAP_ACCOUNT_SCOPE => 2,
        self::CORRELATION_DEVICE_CAP_ACCOUNT_MEMBER => 1,
        self::CORRELATION_DEVICE_CAP_ACCOUNT_BRIDGE => 1,

        self::CORRELATION_CHURN_SCOPE => 1,
        self::CORRELATION_CHURN_MEMBER => 1,
        self::CORRELATION_CHURN_BRIDGE => 1,
        self::CORRELATION_CHURN_WATCH => 1,

        self::CORRELATION_DILUTION_SCOPE => 1,
        self::CORRELATION_DILUTION_MEMBER => 1,
        self::CORRELATION_DILUTION_BRIDGE => 1,
        self::CORRELATION_DILUTION_WATCH => 1,
        self::CORRELATION_DILUTION_CONFIRMATION => 2,

        self::CORRELATION_DISTRIBUTED_DEVICES_SCOPE => 1,
        self::CORRELATION_DISTRIBUTED_DEVICES_BRIDGE => 1,
        self::CORRELATION_DISTRIBUTED_WATCH => 1,

        self::CORRELATION_DISTRIBUTED_OCCURRENCES_SCOPE => 1,
        self::CORRELATION_DISTRIBUTED_OCCURRENCES_WINDOW => 1,
        self::CORRELATION_DISTRIBUTED_OCCURRENCES_BRIDGE => 1,

        self::IPV6_ADAPTIVE_HIERARCHY_SCOPE => 2,
        self::IPV6_ADAPTIVE_HIERARCHY_BRIDGE => 2,
        self::IPV6_ADAPTIVE_CHURN_ANCHOR => 3,

        self::IPV6_ADAPTIVE_CHURN_SCOPE => 2,
        self::IPV6_ADAPTIVE_CHURN_MEMBER => 2,
        self::IPV6_ADAPTIVE_CHURN_BRIDGE => 2,

        self::IPV6_ADAPTIVE_SPRAY_SCOPE => 2,
        self::IPV6_ADAPTIVE_SPRAY_MEMBER => 2,
        self::IPV6_ADAPTIVE_SPRAY_BRIDGE => 2,
    ];

    private function __construct() {}

    public static function isKnown(string $purpose): bool
    {
        return isset(self::SEMANTIC_ARITY[$purpose]);
    }

    /**
     * @throws RateLimiterException when `$purpose` is not registered.
     */
    public static function semanticFieldCount(string $purpose): int
    {
        if (! isset(self::SEMANTIC_ARITY[$purpose])) {
            throw new RateLimiterException('Unknown non-account key purpose.');
        }

        return self::SEMANTIC_ARITY[$purpose];
    }

    /**
     * Total field count `N` including `[purpose, policy, env]`.
     *
     * @throws RateLimiterException when `$purpose` is not registered.
     */
    public static function totalFieldCount(string $purpose): int
    {
        return 3 + self::semanticFieldCount($purpose);
    }

    /**
     * Verify `$purpose` is registered and `$semanticFields` matches its exact semantic arity.
     *
     * @param list<string> $semanticFields
     * @throws RateLimiterException
     */
    public static function assertShape(string $purpose, array $semanticFields): void
    {
        if (count($semanticFields) !== self::semanticFieldCount($purpose)) {
            throw new RateLimiterException('The non-account key purpose and its semantic fields do not match.');
        }
    }

    /**
     * Registered purpose => total tuple length `N` (including `purpose`, `policy`, `env`).
     *
     * @return array<string, int>
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::SEMANTIC_ARITY as $purpose => $semanticCount) {
            $result[$purpose] = 3 + $semanticCount;
        }

        return $result;
    }
}
