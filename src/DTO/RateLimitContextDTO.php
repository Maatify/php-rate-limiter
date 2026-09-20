<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Contains request identity and trust inputs used to derive limiter keys.
 */
final readonly class RateLimitContextDTO implements \JsonSerializable
{
    /**
     * @param string $ip Client IP address used for IP scopes.
     * @param string $ua Raw user-agent string used for normalization.
     * @param ?string $accountId Optional account identifier for account scopes.
     * @param ?array<string, mixed> $clientFingerprint Optional client fingerprint fields.
     * @param ?string $sessionDeviceId Optional trusted-session device identifier.
     * @param bool $isSessionTrusted Whether the session device identifier is trusted.
     * @param array<string, string|string[]> $headers Request headers retained as context.
     * @param bool $isDevicePreviouslyVerifiedForAccount Whether the device is known to the account.
     */
    public function __construct(
        public string $ip,
        public string $ua,
        public ?string $accountId = null,
        public ?array $clientFingerprint = null,
        public ?string $sessionDeviceId = null,
        public bool $isSessionTrusted = false,
        public array $headers = [],
        public bool $isDevicePreviouslyVerifiedForAccount = false,
    ) {}

    /**
     * Return the request context in the public serialized shape.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'ip' => $this->ip,
            'ua' => $this->ua,
            'accountId' => $this->accountId,
            'clientFingerprint' => $this->clientFingerprint,
            'sessionDeviceId' => $this->sessionDeviceId,
            'isSessionTrusted' => $this->isSessionTrusted,
            'headers' => $this->headers,
            'isDevicePreviouslyVerifiedForAccount' => $this->isDevicePreviouslyVerifiedForAccount,
        ];
    }
}
