<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Groups operational snapshots for the standard K1-K5 scopes and IPv6 ranges.
 */
final readonly class RateLimitOperationalScopesDTO implements \JsonSerializable
{
    /**
     * @param RateLimitOperationalKeyStateDTO $k1 Required IP scope.
     * @param RateLimitOperationalKeyStateDTO $k2 Required IP/user-agent scope.
     * @param ?RateLimitOperationalKeyStateDTO $k3 Optional device/IP scope.
     * @param ?RateLimitOperationalKeyStateDTO $k4 Optional account scope.
     * @param ?RateLimitOperationalKeyStateDTO $k5 Optional account/device scope.
     * @param ?RateLimitOperationalKeyStateDTO $k1_48 Optional IPv6 /48 scope.
     * @param ?RateLimitOperationalKeyStateDTO $k1_40 Optional IPv6 /40 scope.
     * @param ?RateLimitOperationalKeyStateDTO $k1_32 Optional IPv6 /32 scope.
     */
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

    /**
     * Return all available scope snapshots in serialized form.
     */
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
