<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitOperationalScopesDTO implements \JsonSerializable
{
    public function __construct(
        public RateLimitOperationalKeyStateDTO $k1,
        public RateLimitOperationalKeyStateDTO $k2,
        public ?RateLimitOperationalKeyStateDTO $k3,
        public ?RateLimitOperationalKeyStateDTO $k4,
        public ?RateLimitOperationalKeyStateDTO $k5,
        public ?RateLimitOperationalKeyStateDTO $k1_48 = null,
        public ?RateLimitOperationalKeyStateDTO $k1_40 = null,
        public ?RateLimitOperationalKeyStateDTO $k1_32 = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'k1' => $this->k1,
            'k2' => $this->k2,
            'k3' => $this->k3,
            'k4' => $this->k4,
            'k5' => $this->k5,
            'k1_48' => $this->k1_48,
            'k1_40' => $this->k1_40,
            'k1_32' => $this->k1_32,
        ];
    }
}
