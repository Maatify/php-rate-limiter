<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

final readonly class PolicyThresholdsDTO implements \JsonSerializable
{
    public function __construct(
        public ?ScoreThresholdsDTO $k1 = null,
        public ?ScoreThresholdsDTO $k2 = null,
        public ?ScoreThresholdsDTO $k3 = null,
        public ?ScoreThresholdsDTO $k4 = null,
        public ?ScoreThresholdsDTO $k5 = null,
        public ?ScoreThresholdsDTO $default = null,
    ) {}

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
