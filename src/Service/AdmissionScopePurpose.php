<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Closed 12-purpose registry for outer-secret Admission `a1` scope and member keys (DEC-048 D2).
 *
 * Every registered purpose fixes its required label (`pdev` => `'set'`; all other 11 => `''`).
 * Shared configuration (`pcfg`), fleet fence, binding epoch, cohort, and permit derivations live in their own
 * dedicated HMAC domains and are intentionally outside this registry.
 *
 * @internal
 */
final class AdmissionScopePurpose
{
    public const SRC = 'src';
    public const ACCT = 'acct';
    public const UFAIL = 'ufail';
    public const PACCT = 'pacct';
    public const PACCTD = 'pacctd';
    public const PPROOF = 'pproof';
    public const PPROOFD = 'pproofd';
    public const PFAIL = 'pfail';
    public const PPEND = 'ppend';
    public const PDEV = 'pdev';
    public const MEMBER = 'member';
    public const DEV = 'dev';

    /** @var array<string, string> purpose token => exact required label */
    private const REQUIRED_LABELS = [
        self::SRC => '',
        self::ACCT => '',
        self::UFAIL => '',
        self::PACCT => '',
        self::PACCTD => '',
        self::PPROOF => '',
        self::PPROOFD => '',
        self::PFAIL => '',
        self::PPEND => '',
        self::PDEV => 'set',
        self::MEMBER => '',
        self::DEV => '',
    ];

    private function __construct() {}

    /**
     * Return all 12 registered Admission scope/member purposes mapped to their exact required label.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::REQUIRED_LABELS;
    }

    /**
     * Assert that `$purpose` is registered in the closed 12-purpose Admission registry and `$label` matches its exact label.
     *
     * @throws RateLimiterException before framing or HMAC when `$purpose` is unknown or `$label` mismatches.
     */
    public static function assertShape(string $purpose, string $label): void
    {
        if (! array_key_exists($purpose, self::REQUIRED_LABELS)) {
            throw new RateLimiterException("Unknown admission scope purpose: {$purpose}.");
        }
        if (self::REQUIRED_LABELS[$purpose] !== $label) {
            throw new RateLimiterException("Invalid label for admission scope purpose {$purpose}.");
        }
    }
}
