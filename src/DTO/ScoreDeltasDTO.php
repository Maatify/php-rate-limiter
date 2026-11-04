<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Defines score increments for access and policy-specific risk signals. Every increment is a non-negative integer
 * (#185, F-185-11).
 */
final readonly class ScoreDeltasDTO implements \JsonSerializable
{
    /**
     * @param int $access Per-access increment, typically used by API policies.
     * @param int $k1_spray IP spray increment.
     * @param int $k2_missing_fp IP/user-agent increment for missing fingerprints.
     * @param int $k4_failure Account failure increment.
     * @param int $k4_repeated_missing_fp Repeated missing-fingerprint increment.
     * @param int $k5_failure Account/device failure increment.
     *
     * @throws RateLimiterException When an increment is negative.
     */
    public function __construct(
        public int $access = 0,
        public int $k1_spray = 0,
        public int $k2_missing_fp = 0,
        public int $k4_failure = 0,
        public int $k4_repeated_missing_fp = 0,
        public int $k5_failure = 0,
    ) {
        foreach (['access' => $access, 'k1_spray' => $k1_spray, 'k2_missing_fp' => $k2_missing_fp, 'k4_failure' => $k4_failure, 'k4_repeated_missing_fp' => $k4_repeated_missing_fp, 'k5_failure' => $k5_failure] as $name => $delta) {
            if ($delta < 0) {
                throw new RateLimiterException('Score delta ' . $name . ' must be a non-negative integer.');
            }
        }
    }

    /**
     * Return all configured score increments in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'access' => $this->access,
            'k1_spray' => $this->k1_spray,
            'k2_missing_fp' => $this->k2_missing_fp,
            'k4_failure' => $this->k4_failure,
            'k4_repeated_missing_fp' => $this->k4_repeated_missing_fp,
            'k5_failure' => $this->k5_failure,
        ];
    }
}
