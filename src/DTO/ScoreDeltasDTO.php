<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class ScoreDeltasDTO implements \JsonSerializable
{
    public function __construct(
        public int $access = 0,
        public int $k1_spray = 0,
        public int $k2_missing_fp = 0,
        public int $k4_failure = 0,
        public int $k4_repeated_missing_fp = 0,
        public int $k5_failure = 0,
    ) {}

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
