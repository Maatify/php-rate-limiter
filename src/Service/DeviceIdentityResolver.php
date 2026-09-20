<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;

/**
 * Normalizes request identity inputs and derives current/previous fingerprints.
 */
class DeviceIdentityResolver implements DeviceIdentityResolverInterface
{
    /**
     * @param FingerprintHasher $currentHasher Hasher for the active key generation.
     * @param ?FingerprintHasher $previousHasher Optional hasher for key rotation.
     */
    public function __construct(
        private readonly FingerprintHasher $currentHasher,
        private readonly ?FingerprintHasher $previousHasher = null,
    ) {}

    /**
     * Resolve normalized identity, confidence, and generation provenance.
     */
    public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
    {
        $ua = $this->normalizeUserAgent($context->ua);
        $clientFp = $context->clientFingerprint ? $this->normalizeClientFp($context->clientFingerprint) : '';
        $sessionFp = $context->sessionDeviceId ?? '';
        $isTrustedSession = $sessionFp !== '' && $context->isSessionTrusted;

        $confidence = 'LOW';
        if (!empty($clientFp)) {
            $confidence = 'MEDIUM';
        }
        if (!empty($sessionFp) && $context->isSessionTrusted) {
            $confidence = 'HIGH';
        }

        $rawString = "v1|{$ua}|{$clientFp}|{$sessionFp}";
        $hash = $this->currentHasher->hash($rawString);
        $previousHash = $this->previousHasher?->hash($rawString);

        return new DeviceIdentityDTO(
            $hash,
            $confidence,
            $isTrustedSession,
            false,
            $ua,
            $context->isDevicePreviouslyVerifiedForAccount,
            $previousHash,
        );
    }

    /**
     * Reduce a raw user-agent to a stable browser-major or bounded fallback value.
     */
    public static function normalizeUserAgent(string $ua): string
    {
        if (preg_match('#(Chrome|Firefox|Safari|Edge|OPR)/(\d+)#', $ua, $matches)) {
            return strtolower($matches[1] . '/' . $matches[2]);
        }
        return strtolower(substr($ua, 0, 50));
    }

    /**
     * @param array<string, mixed> $fp
     */
    private function normalizeClientFp(array $fp): string
    {
        ksort($fp);
        $json = json_encode($fp);
        return $json === false ? '' : $json;
    }
}
