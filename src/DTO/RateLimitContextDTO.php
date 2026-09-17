<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitContextDTO implements \JsonSerializable
{
    /**
     * @param string $ip
     * @param string $ua
     * @param ?string $accountId
     * @param ?array<string, mixed> $clientFingerprint
     * @param ?string $sessionDeviceId
     * @param bool $isSessionTrusted
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        public string $ip,
        public string $ua,
        public ?string $accountId = null,
        public ?array $clientFingerprint = null,
        public ?string $sessionDeviceId = null,
        public bool $isSessionTrusted = false,
        public array $headers = []
    ) {}

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
        ];
    }
}
