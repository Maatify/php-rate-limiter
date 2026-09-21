<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Reports whether device-cap correlation requires an ephemeral shared key.
 */
final readonly class EphemeralStateDTO implements \JsonSerializable
{
    /**
     * @param bool $isEphemeral Whether the real fingerprint must be collapsed.
     * @param int $accountDeviceCount Distinct devices seen for the account/IP scope.
     * @param int $ipDeviceCount Distinct devices seen for the IP scope.
     */
    public function __construct(
        public bool $isEphemeral,
        public int $accountDeviceCount,
        public int $ipDeviceCount,
    ) {}

    /**
     * Return the device-cap state as a stable field map.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'isEphemeral' => $this->isEphemeral,
            'accountDeviceCount' => $this->accountDeviceCount,
            'ipDeviceCount' => $this->ipDeviceCount,
        ];
    }
}
