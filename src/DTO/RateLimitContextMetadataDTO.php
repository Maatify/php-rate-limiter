<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitContextMetadataDTO implements \JsonSerializable
{
    public function __construct(
        public ?string $reason = null,
        public ?string $scope = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'reason' => $this->reason,
            'scope' => $this->scope,
        ];
    }
}
