<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

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
        $clientFp = $context->clientFingerprint !== null && $context->clientFingerprint !== []
            ? $this->normalizeClientFp($context->clientFingerprint)
            : '';
        $sessionFp = $context->sessionDeviceId ?? '';
        $isTrustedSession = $sessionFp !== '' && $context->isSessionTrusted;

        $confidence = $isTrustedSession ? 'HIGH' : ($clientFp !== '' ? 'MEDIUM' : 'LOW');

        $rawString = "v2|{$ua}|{$clientFp}|{$sessionFp}";
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
        $patterns = [
            'opera' => '#OPR/(\d+)#i',
            'edge' => '#(?:EdgA|EdgiOS|Edg|Edge)/(\d+)#i',
            'firefox' => '#(?:Firefox|FxiOS)/(\d+)#i',
            'chrome' => '#(?:Chrome|CriOS)/(\d+)#i',
            'safari' => '#Version/(\d+)(?=.*Safari/)#i',
        ];

        foreach ($patterns as $browser => $pattern) {
            if (preg_match($pattern, $ua, $matches) === 1) {
                return "{$browser}/{$matches[1]}";
            }
        }

        return 'other/0';
    }

    /**
     * @param array<string, mixed> $fp
     */
    private function normalizeClientFp(array $fp): string
    {
        try {
            // Validate cycles, depth, and serialization before recursive canonicalization.
            json_encode(
                $fp,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                512,
            );

            return json_encode(
                $this->canonicalizeClientFingerprint($fp),
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                512,
            );
        } catch (\JsonException $exception) {
            throw new RateLimiterException(
                'Client fingerprint payload could not be serialized.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>|\stdClass
     */
    private function canonicalizeClientFingerprint(array $value): array|\stdClass
    {
        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = $this->canonicalizeClientFingerprintValue($item);
            }

            return $normalized;
        }

        $keys = array_keys($value);
        usort(
            $keys,
            static fn(int|string $left, int|string $right): int => strcmp((string) $left, (string) $right),
        );

        $normalized = new \stdClass();
        foreach ($keys as $key) {
            $normalized->{(string) $key} = $this->canonicalizeClientFingerprintValue($value[$key]);
        }

        return $normalized;
    }

    /**
     * @return array<array-key, mixed>|bool|float|int|string|\stdClass|null
     */
    private function canonicalizeClientFingerprintValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->canonicalizeClientFingerprint($value);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new RateLimiterException('Client fingerprint payload could not be serialized.');
    }
}
