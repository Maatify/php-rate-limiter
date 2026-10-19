<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialVerificationDTO;
use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Encodes and verifies admission credentials. It is the format authority and nothing more: it never decides
 * when a credential is issued, for how long, or to whom (those are Host decisions).
 *
 * A credential is `base64url(payload) . '.' . base64url(HMAC-SHA256(domain . payload, bindingKey))`. The
 * binding key rotates independently of the key and fingerprint secrets: the current key signs, the current
 * and at most one previous key verify, selected by the key id inside the payload.
 *
 * Verification never throws on a bad credential; it returns a verdict. Anything but VALID means "not proven".
 */
final class AdmissionCredentialCodec
{
    private const VERSION = 1;
    private const MAX_LENGTH = 4096;
    private const DOMAIN = 'maatify.admission.credential.v1|';
    private const KEY_ID_DOMAIN = 'maatify.admission.key-id.v1';
    private const KEYS = ['v', 'sub', 'grp', 'ins', 'jti', 'exp', 'bat', 'epo', 'kid'];

    private readonly string $currentKeyId;

    private readonly ?string $previousKeyId;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $bindingKey,
        #[\SensitiveParameter]
        private readonly ?string $previousBindingKey = null,
    ) {
        if (trim($bindingKey) === '') {
            throw new RateLimiterException('Binding key must not be empty or whitespace-only.');
        }
        if ($previousBindingKey !== null && trim($previousBindingKey) === '') {
            throw new RateLimiterException('Previous binding key must not be empty or whitespace-only.');
        }

        $this->currentKeyId = self::keyId($bindingKey);
        $this->previousKeyId = $previousBindingKey === null ? null : self::keyId($previousBindingKey);
    }

    /** Sign the claims with the current key. The key id of the claims is replaced by the current key's id. */
    public function encode(AdmissionCredentialDTO $claims): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'v' => self::VERSION,
            'sub' => $claims->subject,
            'grp' => $claims->budgetGroups,
            'ins' => $claims->instance,
            'jti' => $claims->jti,
            'exp' => $claims->expiresAt,
            'bat' => $claims->boundAt,
            'epo' => $claims->epoch,
            'kid' => $this->currentKeyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload . '.' . self::base64UrlEncode($this->mac($payload, $this->bindingKey));
    }

    /**
     * Verify integrity, then that the credential names this subject, covers this budget group, is bound to
     * this instance, has not expired and carries the account's current epoch. `$currentEpoch` is read by the
     * caller; `$now` is the caller's clock.
     */
    public function verify(
        string $credential,
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
        if ($key === null || $mac === null || ! hash_equals($this->mac($payloadPart, $key), $mac)) {
            return $verdict(AdmissionCredentialVerdictEnum::INVALID_SIGNATURE);
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
            || ! is_string($data['sub']) || ! is_string($data['ins']) || ! is_string($data['jti'])
            || ! is_int($data['exp']) || ! is_int($data['bat']) || ! is_int($data['epo'])
            || ! is_string($data['kid'])) {
            return null;
        }
        foreach ($groups as $group) {
            if (! is_string($group)) {
                return null;
            }
        }

        try {
            return new AdmissionCredentialDTO(
                $data['sub'],
                $groups,
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

    private function mac(string $payloadPart, string $key): string
    {
        return hash_hmac('sha256', self::DOMAIN . $payloadPart, $key, true);
    }

    private static function keyId(string $key): string
    {
        return substr(hash_hmac('sha256', self::KEY_ID_DOMAIN, $key), 0, 16);
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
