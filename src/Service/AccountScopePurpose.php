<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The purpose registry of the account-bearing keys (E1 contract §3.4). A purpose absent from it is not derived.
 *
 * @internal
 */
final class AccountScopePurpose
{
    public const K4 = 'k4';
    public const K4P = 'k4p';
    public const K5 = 'k5';
    public const K5P = 'k5p';
    public const MICROCAP_K5 = 'microcap.k5';
    public const MICROCAP_K5P = 'microcap.k5p';
    public const BUDGET_COOLDOWN = 'budget_cooldown';
    public const BUDGET_COOLDOWN_P = 'budget_cooldown_p';

    /** Purposes that carry a material field after the account. */
    private const WITH_MATERIAL = [
        self::K5 => true,
        self::K5P => true,
        self::MICROCAP_K5 => true,
        self::MICROCAP_K5P => true,
        'aux.anti_equilibrium_device' => true,
        'aux.anti_equilibrium_device_p' => true,
    ];

    /** Purposes without a material. */
    private const WITHOUT_MATERIAL = [
        self::K4 => true,
        self::K4P => true,
        self::BUDGET_COOLDOWN => true,
        self::BUDGET_COOLDOWN_P => true,
        'aux.last_missing_fp' => true,
        'aux.flood_stage' => true,
        'aux.anti_equilibrium' => true,
        'aux.anti_equilibrium_p' => true,
        'aux.known_device_allowance' => true,
        'aux.known_device_allowance_p' => true,
    ];

    private function __construct() {}

    public static function isKnown(string $purpose): bool
    {
        return isset(self::WITH_MATERIAL[$purpose]) || isset(self::WITHOUT_MATERIAL[$purpose]);
    }

    public static function hasMaterial(string $purpose): bool
    {
        if (! self::isKnown($purpose)) {
            throw new RateLimiterException('Unknown account-scope key purpose.');
        }

        return isset(self::WITH_MATERIAL[$purpose]);
    }

    /** The purpose of an auxiliary account state: `aux.` plus its (lane-suffixed) name. */
    public static function aux(string $name): string
    {
        return 'aux.' . $name;
    }

    /**
     * Check a purpose and its material against the registry.
     *
     * @throws RateLimiterException
     */
    public static function assertShape(string $purpose, ?string $material): void
    {
        if (self::hasMaterial($purpose) !== ($material !== null)) {
            throw new RateLimiterException('The account-scope key purpose and its material do not match.');
        }
    }
}
