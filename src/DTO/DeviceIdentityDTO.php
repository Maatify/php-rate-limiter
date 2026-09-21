<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Carries the normalized device identity and trust signals for one request.
 */
final readonly class DeviceIdentityDTO implements \JsonSerializable
{
    /**
     * @param ?string $fingerprintHash Current-generation fingerprint hash.
     * @param string $confidence Identity confidence: LOW, MEDIUM, or HIGH.
     * @param bool $isTrustedSession Whether the session supplies trusted identity.
     * @param bool $churnDetected Whether identity churn was detected for the request.
     * @param string $normalizedUa Coarse normalized user-agent representation.
     * @param bool $isDevicePreviouslyVerifiedForAccount Whether the device is known to the account.
     * @param ?string $previousFingerprintHash Previous-generation fingerprint hash, when available.
     */
    public function __construct(
        public ?string $fingerprintHash,
        public string $confidence, // LOW, MEDIUM, HIGH
        public bool $isTrustedSession,
        public bool $churnDetected = false,
        public string $normalizedUa = '',
        public bool $isDevicePreviouslyVerifiedForAccount = false,
        public ?string $previousFingerprintHash = null,
    ) {}

    /**
     * Return identity and trust signals in the public serialized shape.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'fingerprintHash' => $this->fingerprintHash,
            'confidence' => $this->confidence,
            'isTrustedSession' => $this->isTrustedSession,
            'churnDetected' => $this->churnDetected,
            'normalizedUa' => $this->normalizedUa,
            'isDevicePreviouslyVerifiedForAccount' => $this->isDevicePreviouslyVerifiedForAccount,
            'previousFingerprintHash' => $this->previousFingerprintHash,
        ];
    }
}
