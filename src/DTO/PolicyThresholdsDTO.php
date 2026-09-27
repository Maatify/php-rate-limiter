<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/**
 * Maps limiter scopes to their soft and hard score thresholds.
 *
 * A null scope has no dedicated thresholds and may use the default mapping.
 */
final readonly class PolicyThresholdsDTO implements \JsonSerializable
{
    /**
     * @param ?ScoreThresholdsDTO $k1 IP scope thresholds.
     * @param ?ScoreThresholdsDTO $k2 IP/user-agent scope thresholds.
     * @param ?ScoreThresholdsDTO $k3 Device/IP scope thresholds.
     * @param ?ScoreThresholdsDTO $k4 Account scope thresholds.
     * @param ?ScoreThresholdsDTO $k5 Account/device scope thresholds.
     * @param ?ScoreThresholdsDTO $default Fallback thresholds for an unmapped scope.
     */
    public function __construct(
        public ?ScoreThresholdsDTO $k1 = null,
        public ?ScoreThresholdsDTO $k2 = null,
        public ?ScoreThresholdsDTO $k3 = null,
        public ?ScoreThresholdsDTO $k4 = null,
        public ?ScoreThresholdsDTO $k5 = null,
        public ?ScoreThresholdsDTO $default = null,
    ) {}

    /**
     * Return the scope-to-threshold mapping in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'k1' => $this->k1,
            'k2' => $this->k2,
            'k3' => $this->k3,
            'k4' => $this->k4,
            'k5' => $this->k5,
            'default' => $this->default,
        ];
    }
}
