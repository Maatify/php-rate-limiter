<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialVerificationDTO;
use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Encodes and verifies v2 admission credentials and v2 permit tokens bound to an explicit trust context
 * (`environmentScope`, `admissionTrustDomain`, and `admissionStateSecret`; DEC-048 D1/D5/D6).
 *
 * It is the format authority and nothing more: it never decides when a credential is issued, for how long,
 * or to whom (those are Host decisions).
 *
 * Both the Host issuer and the Builder runtime should obtain a codec from the same `RateLimiterConfig` via
 * {@see self::fromConfig()}, or supply the exact same trust context explicitly. Binding keys rotate
 * independently of outer key, fingerprint, and state secrets: the current binding key signs, and the current
 * and at most one previous binding key verify. Legacy v1 tokens are unconditionally rejected.
 *
 * Verification never throws on a bad credential; it returns a verdict. Anything but VALID means "not proven".
 */
final class AdmissionCredentialCodec
{
    public const VERSION = 2;
    public const MAX_LENGTH = 4096;
    public const PERMIT_MAX_LENGTH = 512;

    private const KEYS = ['v', 'acc', 'sub', 'grp', 'ins', 'jti', 'exp', 'bat', 'epo', 'kid'];

    private readonly AdmissionKeyDeriver $keys;

    private readonly string $currentKeyId;

    private readonly ?string $previousKeyId;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $bindingKey,
        string $environmentScope,
        string $admissionTrustDomain,
        #[\SensitiveParameter]
        string $admissionStateSecret,
        #[\SensitiveParameter]
        private readonly ?string $previousBindingKey = null,
    ) {
        if (! AdmissionKeyDeriver::isNonBlank($bindingKey)) {
            throw new RateLimiterException('Binding key must not be empty or whitespace-only.');
        }
        if ($previousBindingKey !== null && ! AdmissionKeyDeriver::isNonBlank($previousBindingKey)) {
            throw new RateLimiterException('Previous binding key must not be empty or whitespace-only.');
        }

        $this->keys = new AdmissionKeyDeriver($environmentScope, $admissionTrustDomain, $admissionStateSecret);
        $this->currentKeyId = $this->keys->bindingKeyId($bindingKey);
        $this->previousKeyId = $previousBindingKey === null ? null : $this->keys->bindingKeyId($previousBindingKey);
    }

    /**
     * Construct a codec sharing the exact environment scope, trust domain, state secret, and binding keys of
     * `$config` (DEC-048 D1). This is the unified setup path for both the Host issuer and `RateLimiterBuilder`.
     *
     * @throws RateLimiterException when `$config` lacks `bindingKeySecret`, `admissionTrustDomain`, or `admissionStateSecret`.
     */
    public static function fromConfig(RateLimiterConfig $config): self
    {
        $bindingKey = $config->bindingKeySecret();
        $trustDomain = $config->admissionTrustDomain();
        $stateSecret = $config->admissionStateSecret();
        if ($bindingKey === null || $trustDomain === null || $stateSecret === null) {
            throw new RateLimiterException(
                'AdmissionCredentialCodec::fromConfig() requires bindingKeySecret, admissionTrustDomain, and admissionStateSecret on RateLimiterConfig.',
            );
        }

        return new self(
            $bindingKey,
            $config->environmentScope(),
            $trustDomain,
            $stateSecret,
            $config->previousBindingKeySecret(),
        );
    }

    /** Opaque 64-character lowercase hex cohort derived from this codec's trust context. */
    public function cohort(): string
    {
        return $this->keys->cohort();
    }

    /** Sign the v2 claims with the current binding key under this trust context's framed credential MAC domain. */
    public function encode(AdmissionCredentialDTO $claims): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'v' => self::VERSION,
            'acc' => $claims->account,
            'sub' => $claims->subject,
            'grp' => $claims->budgetGroups,
            'ins' => $claims->instance,
            'jti' => $claims->jti,
            'exp' => $claims->expiresAt,
            'bat' => $claims->boundAt,
            'epo' => $claims->epoch,
            'kid' => $this->currentKeyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload . '.' . self::base64UrlEncode($this->keys->credentialMac($payload, $this->bindingKey));
    }

    /**
     * Verify v2 integrity and trust-context MAC, then verify explicit `$account` (revocation identity),
     * effective `$subject` (correlation identity), `$budgetGroup`, `$instance`, expiry at `$now`, and
     * `$currentEpoch`. Legacy v1 credentials are always rejected. Never throws on bad credentials.
     */
    public function verify(
        string $credential,
        string $account,
        string $subject,
        string $budgetGroup,
        string $instance,
        int $now,
        int $currentEpoch,
    ): AdmissionCredentialVerificationDTO {
        $verdict = static fn(AdmissionCredentialVerdictEnum $v): AdmissionCredentialVerificationDTO
            => new AdmissionCredentialVerificationDTO($v);

        if ($credential === '' || strlen($credential) > self::MAX_LENGTH) {
            return $verdict(AdmissionCredentialVerdictEnum::MALFORMED);
        }
        $parts = explode('.', $credential);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return $verdict(AdmissionCredentialVerdictEnum::MALFORMED);
        }
        [$payloadPart, $macPart] = $parts;

        $claims = $this->decodePayload($payloadPart);
        if ($claims === null) {
            return $verdict(AdmissionCredentialVerdictEnum::MALFORMED);
        }

        $key = match ($claims->keyId) {
            $this->currentKeyId => $this->bindingKey,
            $this->previousKeyId => $this->previousBindingKey,
            default => null,
        };
        $mac = self::base64UrlDecode($macPart);
        if ($key === null || $mac === null || ! hash_equals($this->keys->credentialMac($payloadPart, $key), $mac)) {
            return $verdict(AdmissionCredentialVerdictEnum::INVALID_SIGNATURE);
        }

        if (! hash_equals($claims->account, $account)) {
            return $verdict(AdmissionCredentialVerdictEnum::WRONG_ACCOUNT);
        }
        if (! hash_equals($claims->subject, $subject)) {
            return $verdict(AdmissionCredentialVerdictEnum::WRONG_SUBJECT);
        }
        if (! in_array($budgetGroup, $claims->budgetGroups, true)) {
            return $verdict(AdmissionCredentialVerdictEnum::WRONG_BUDGET_GROUP);
        }
        if (! hash_equals($claims->instance, $instance)) {
            return $verdict(AdmissionCredentialVerdictEnum::WRONG_INSTANCE);
        }
        if ($now >= $claims->expiresAt) {
            return $verdict(AdmissionCredentialVerdictEnum::EXPIRED);
        }
        if ($claims->epoch !== $currentEpoch) {
            return $verdict(AdmissionCredentialVerdictEnum::STALE_EPOCH);
        }

        return new AdmissionCredentialVerificationDTO(AdmissionCredentialVerdictEnum::VALID, $claims);
    }

    /**
     * Sign a bounded v2 permit-token payload with the current binding key under the framed permit MAC domain
     * (DEC-048 D5/D6), enforcing the 512-character maximum token length.
     *
     * @throws RateLimiterException when the encoded signed permit token exceeds 512 characters.
     */
    public function signPermitToken(string $payload): string
    {
        $part = self::base64UrlEncode($payload);
        $token = $part . '.' . self::base64UrlEncode($this->keys->permitMac($part, $this->bindingKey));
        if (strlen($token) > self::PERMIT_MAX_LENGTH) {
            throw new RateLimiterException('Signed admission permit token exceeds the maximum length of ' . self::PERMIT_MAX_LENGTH . ' characters.');
        }

        return $token;
    }

    /**
     * Return the payload of a bounded v2 permit token signed by the current or previous binding key within this
     * trust context, else null (never throws). Rejects tokens over 512 characters and all legacy v1 tokens.
     */
    public function verifyPermitToken(string $token): ?string
    {
        if ($token === '' || strlen($token) > self::PERMIT_MAX_LENGTH) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $mac = self::base64UrlDecode($parts[1]);
        $payload = self::base64UrlDecode($parts[0]);
        if ($mac === null || $payload === null) {
            return null;
        }
        foreach ([$this->bindingKey, $this->previousBindingKey] as $key) {
            if ($key !== null && hash_equals($this->keys->permitMac($parts[0], $key), $mac)) {
                return $payload;
            }
        }

        return null;
    }

    private function decodePayload(string $payloadPart): ?AdmissionCredentialDTO
    {
        $json = self::base64UrlDecode($payloadPart);
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || array_keys($data) !== self::KEYS || $data['v'] !== self::VERSION) {
            return null;
        }
        $groups = $data['grp'];
        if (! is_array($groups) || ! array_is_list($groups)
            || ! is_string($data['acc']) || ! is_string($data['sub']) || ! is_string($data['ins']) || ! is_string($data['jti'])
            || ! is_int($data['exp']) || ! is_int($data['bat']) || ! is_int($data['epo'])
            || ! is_string($data['kid'])) {
            return null;
        }
        $budgetGroups = [];
        foreach ($groups as $group) {
            if (! is_string($group)) {
                return null;
            }
            $budgetGroups[] = $group;
        }

        try {
            return new AdmissionCredentialDTO(
                $data['acc'],
                $data['sub'],
                $budgetGroups,
                $data['ins'],
                $data['jti'],
                $data['exp'],
                $data['bat'],
                $data['epo'],
                $data['kid'],
            );
        } catch (RateLimiterException) {
            return null;
        }
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Canonical decoding only: a string that does not re-encode to itself is rejected. */
    private static function base64UrlDecode(string $encoded): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false || self::base64UrlEncode($raw) !== $encoded) {
            return null;
        }

        return $raw;
    }
}
