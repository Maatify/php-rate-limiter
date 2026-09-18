<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class DeviceIdentityDTO implements \JsonSerializable
{
    public function __construct(
        public ?string $fingerprintHash,
        public string $confidence, // LOW, MEDIUM, HIGH
        public bool $isTrustedSession,
        public bool $churnDetected = false,
        public string $normalizedUa = '',
        public bool $isDevicePreviouslyVerifiedForAccount = false
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'fingerprintHash' => $this->fingerprintHash,
            'confidence' => $this->confidence,
            'isTrustedSession' => $this->isTrustedSession,
            'churnDetected' => $this->churnDetected,
            'normalizedUa' => $this->normalizedUa,
            'isDevicePreviouslyVerifiedForAccount' => $this->isDevicePreviouslyVerifiedForAccount,
        ];
    }
}
