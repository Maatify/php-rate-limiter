<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Groups operational snapshots for the canonical K1-K5 enforcement scopes.
 *
 * IPv6 adaptive /48, /40, and /32 state is internal correlation state and is
 * intentionally not exposed by the enforcement operational read contract.
 */
final readonly class RateLimitOperationalScopesDTO implements \JsonSerializable
{
    /**
     * @param RateLimitOperationalKeyStateDTO $k1 Required IP scope.
     * @param RateLimitOperationalKeyStateDTO $k2 Required IP/user-agent scope.
     * @param ?RateLimitOperationalKeyStateDTO $k3 Optional device/IP scope.
     * @param ?RateLimitOperationalKeyStateDTO $k4 Optional account scope.
     * @param ?RateLimitOperationalKeyStateDTO $k5 Optional account/device scope.
     */
    public function __construct(
        public RateLimitOperationalKeyStateDTO $k1,
        public RateLimitOperationalKeyStateDTO $k2,
        public ?RateLimitOperationalKeyStateDTO $k3,
        public ?RateLimitOperationalKeyStateDTO $k4,
        public ?RateLimitOperationalKeyStateDTO $k5,
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
        ];
    }
}
