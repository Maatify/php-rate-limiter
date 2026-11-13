<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * The effective shared admission configuration of one budget group as one worker holds it (DEC-038): today the proven-lane
 * pending-permit limit, `off` when none is configured. Every worker on one Redis registers the same value under the opaque
 * `key` (current secret) and `previousKey` (previous secret, read only, so a key rotation keeps the registration).
 */
final readonly class AdmissionSharedConfigDTO
{
    public const OFF = 'off';

    public function __construct(
        public string $key,
        public ?string $previousKey,
        public string $value,
    ) {
        if (trim($key) === '' || ($previousKey !== null && trim($previousKey) === '')) {
            throw new RateLimiterException('Admission shared configuration keys must not be blank.');
        }
        if ($value !== self::OFF && (preg_match('/\A[1-9][0-9]{0,15}\z/', $value) !== 1 || ! AdmissionBudgetsDTO::isWithinPendingPermitLimitMaximum($value))) {
            throw new RateLimiterException('Admission shared configuration value must be "off" or an integer from 1 to ' . AdmissionBudgetsDTO::PROVEN_PENDING_PERMIT_LIMIT_MAX_DECIMAL . '.');
        }
    }

    public static function forLimit(string $key, ?string $previousKey, ?int $limit): self
    {
        return new self($key, $previousKey, $limit === null ? self::OFF : (string) $limit);
    }
}
