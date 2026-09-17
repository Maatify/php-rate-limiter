<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class EphemeralStateDTO implements \JsonSerializable
{
    public function __construct(
        public bool $isEphemeral,
        public int $accountDeviceCount,
        public int $ipDeviceCount
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'isEphemeral' => $this->isEphemeral,
            'accountDeviceCount' => $this->accountDeviceCount,
            'ipDeviceCount' => $this->ipDeviceCount,
        ];
    }
}
