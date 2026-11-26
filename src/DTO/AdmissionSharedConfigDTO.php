<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The effective shared admission configuration of one budget group as one worker holds it (DEC-049, successor of DEC-038):
 * today the proven-lane pending-permit limit, `off` when none is configured. Every worker in the same trust context on one
 * Redis registers the same value under one durable opaque `key` derived from `admissionStateSecret` (no `previousKey`;
 * survives arbitrary outer key-secret rotations).
 */
final readonly class AdmissionSharedConfigDTO
{
    public const OFF = 'off';

    public function __construct(
        public string $key,
        public string $value,
    ) {
        if (trim($key, " \t\n\r\v\f") === '') {
            throw new RateLimiterException('Admission shared configuration key must not be blank.');
        }
        if ($value !== self::OFF && (preg_match('/\A[1-9][0-9]{0,15}\z/', $value) !== 1 || ! AdmissionBudgetsDTO::isWithinPendingPermitLimitMaximum($value))) {
            throw new RateLimiterException('Admission shared configuration value must be "off" or an integer from 1 to ' . AdmissionBudgetsDTO::PROVEN_PENDING_PERMIT_LIMIT_MAX_DECIMAL . '.');
        }
    }

    /**
     * The configuration of a worker whose proven-lane pending-permit limit is `$limit`; `null` means none is
     * configured and gives the value `off`.
     */
    public static function forLimit(string $key, ?int $limit): self
    {
        return new self($key, $limit === null ? self::OFF : (string) $limit);
    }
}
