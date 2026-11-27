<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Canonical Admission `a1` key, cohort, token-MAC, and permit-commitment derivation (DEC-048, DEC-049).
 *
 * Every preimage uses `KeyPreimageFraming::encodeTuple()` (`u32be` checked byte lengths and tuple counts in `0..4294967295`
 * before packing or HMAC, with zero trimming or normalization of opaque field bytes) and a dedicated Admission `a1` / `v2`
 * magic header and HMAC subkey domain.
 *
 * @internal
 */
final class AdmissionKeyDeriver
{
    public const SCHEMA_VERSION = 'a1';

    public const MAGIC_COHORT = "maatify.rate-limiter.admission.cohort.a1\x00";
    public const KEY_DOMAIN_COHORT = 'maatify.rate-limiter.key-domain.admission.cohort.a1';

    public const MAGIC_SCOPE = "maatify.rate-limiter.admission.scope.a1\x00";
    public const KEY_DOMAIN_SCOPE = 'maatify.rate-limiter.key-domain.admission.scope.a1';

    public const MAGIC_FENCE = "maatify.rate-limiter.admission.fence.a1\x00";
    public const KEY_DOMAIN_FENCE = 'maatify.rate-limiter.key-domain.admission.fence.a1';

    public const MAGIC_EPOCH = "maatify.rate-limiter.admission.epoch.a1\x00";
    public const KEY_DOMAIN_EPOCH = 'maatify.rate-limiter.key-domain.admission.epoch.a1';

    public const MAGIC_CONFIG = "maatify.rate-limiter.admission.config.a1\x00";
    public const KEY_DOMAIN_CONFIG = 'maatify.rate-limiter.key-domain.admission.config.a1';

    public const MAGIC_PERMIT_COMMITMENT = "maatify.rate-limiter.admission.permit-ctx.a1\x00";
    public const KEY_DOMAIN_PERMIT_COMMITMENT = 'maatify.rate-limiter.key-domain.admission.permit-ctx.a1';

    public const MAGIC_PERMIT_RECORD = "maatify.rate-limiter.admission.permit-record.a1\x00";
    public const KEY_DOMAIN_PERMIT_RECORD = 'maatify.rate-limiter.key-domain.admission.permit-record.a1';

    public const MAGIC_CREDENTIAL_MAC = "maatify.rate-limiter.admission.credential-mac.v2\x00";
    public const KEY_DOMAIN_CREDENTIAL_MAC = 'maatify.rate-limiter.key-domain.admission.credential-mac.v2';

    public const MAGIC_PERMIT_MAC = "maatify.rate-limiter.admission.permit-mac.v2\x00";
    public const KEY_DOMAIN_PERMIT_MAC = 'maatify.rate-limiter.key-domain.admission.permit-mac.v2';

    public const MAGIC_KEY_ID = "maatify.rate-limiter.admission.key-id.v2\x00";
    public const KEY_DOMAIN_KEY_ID = 'maatify.rate-limiter.key-domain.admission.key-id.v2';

    /** ASCII whitespace characters only — preserves opaque NUL (`\x00`) bytes as non-blank content. */
    private const ASCII_WHITESPACE = " \t\n\r\v\f";

    private readonly string $cohort;

    /**
     * @param ?\Closure(string): int $length Internal boundary seam for testing field lengths without huge allocations.
     * @param ?\Closure(list<string>): int $count Internal boundary seam for testing tuple counts without huge allocations.
     */
    public function __construct(
        private readonly string $environmentScope,
        private readonly string $admissionTrustDomain,
        #[\SensitiveParameter]
        private readonly string $admissionStateSecret,
        private readonly ?\Closure $length = null,
        private readonly ?\Closure $count = null,
    ) {
        if (! self::isNonBlank($environmentScope)) {
            throw new RateLimiterException('Admission environment scope must not be empty or whitespace-only.');
        }
        if (! self::isNonBlank($admissionTrustDomain)) {
            throw new RateLimiterException('Admission trust domain must not be empty or whitespace-only.');
        }
        if (! self::isNonBlank($admissionStateSecret)) {
            throw new RateLimiterException('Admission state secret must not be empty or whitespace-only.');
        }
        if (strlen($admissionStateSecret) < RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES) {
            throw new RateLimiterException(
                'Admission state secret must be at least ' . RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES . ' bytes.',
            );
        }

        $this->cohort = $this->hmacHex(
            $this->admissionStateSecret,
            self::KEY_DOMAIN_COHORT,
            self::MAGIC_COHORT,
            [self::SCHEMA_VERSION, $this->environmentScope, $this->admissionTrustDomain],
            'admission cohort',
        );
    }

    /** Whether an opaque byte string contains at least one non-ASCII-whitespace byte (`"\0"` is non-blank). */
    public static function isNonBlank(string $value): bool
    {
        return trim($value, self::ASCII_WHITESPACE) !== '';
    }

    /** Opaque 64-character lowercase hex cohort derived from `(schema, env, authority)` under `admissionStateSecret`. */
    public function cohort(): string
    {
        return $this->cohort;
    }

    public function environmentScope(): string
    {
        return $this->environmentScope;
    }

    public function admissionTrustDomain(): string
    {
        return $this->admissionTrustDomain;
    }

    /**
     * Derive a logical key for one of the 12 registered Admission scope/member purposes under an outer key secret.
     *
     * Logical form: `a1.scope.{purpose}.{64 lowercase hex}`.
     *
     * @throws RateLimiterException when `$purpose`/`$label` violates `AdmissionScopePurpose::assertShape()`, `$outerSecret` is blank/whitespace-only, or tuple framing overflows.
     */
    public function scopeKey(
        string $budgetGroup,
        string $purpose,
        string $value,
        string $label,
        #[\SensitiveParameter]
        string $outerSecret,
    ): string {
        AdmissionScopePurpose::assertShape($purpose, $label);
        if (! self::isNonBlank($outerSecret)) {
            throw new RateLimiterException('Admission outer key secret must not be empty or whitespace-only.');
        }

        $digest = $this->hmacHex(
            $outerSecret,
            self::KEY_DOMAIN_SCOPE,
            self::MAGIC_SCOPE,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, $budgetGroup, $purpose, $value, $label],
            'admission scope key',
        );

        return 'a1.scope.' . $purpose . '.' . $digest;
    }

    /**
     * Derive the logical fleet-fence key for `$budgetGroup` under an outer key secret (current or previous).
     *
     * Logical form: `a1.fence.{64 lowercase hex}`.
     *
     * @throws RateLimiterException when `$outerSecret` is blank/whitespace-only or tuple framing overflows.
     */
    public function fenceKey(string $budgetGroup, #[\SensitiveParameter] string $outerSecret): string
    {
        if (! self::isNonBlank($outerSecret)) {
            throw new RateLimiterException('Admission outer key secret must not be empty or whitespace-only.');
        }

        $digest = $this->hmacHex(
            $outerSecret,
            self::KEY_DOMAIN_FENCE,
            self::MAGIC_FENCE,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, $budgetGroup],
            'admission fence key',
        );

        return 'a1.fence.' . $digest;
    }

    /**
     * Derive the durable account-wide binding-epoch key under `admissionStateSecret` (independent of outer rotation).
     *
     * Logical form: `a1.epoch.{64 lowercase hex}`.
     */
    public function epochKey(string $accountId): string
    {
        $digest = $this->hmacHex(
            $this->admissionStateSecret,
            self::KEY_DOMAIN_EPOCH,
            self::MAGIC_EPOCH,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, $accountId],
            'admission binding epoch key',
        );

        return 'a1.epoch.' . $digest;
    }

    /**
     * Derive the durable shared-configuration (`pcfg`) key for `$budgetGroup` under `admissionStateSecret` (DEC-049).
     *
     * Logical form: `a1.pcfg.{64 lowercase hex}`.
     */
    public function configKey(string $budgetGroup): string
    {
        $digest = $this->hmacHex(
            $this->admissionStateSecret,
            self::KEY_DOMAIN_CONFIG,
            self::MAGIC_CONFIG,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, $budgetGroup, 'pcfg'],
            'admission shared config key',
        );

        return 'a1.pcfg.' . $digest;
    }

    /**
     * Fixed-length keyed commitment (`64` lowercase hex) binding an admission permit to its trust cohort, group, operation,
     * canonical admission source, explicit presence/value of account, effective subject, client instance, and digest of any
     * presented admission credential (DEC-048 D6). Uses `admissionStateSecret` only (never a rotating secret).
     */
    public function permitContextCommitment(string $budgetGroup, string $operation, RateLimitContextDTO $context): string
    {
        $canonicalSource = SourcePrefix::of($context->ip);
        $account = IdentityPresence::accountId($context);
        $subject = IdentityPresence::subject($context);
        $instance = IdentityPresence::isPresent($context->clientInstanceId) ? $context->clientInstanceId : null;
        $credential = IdentityPresence::isPresent($context->admissionCredential) ? $context->admissionCredential : null;

        $fields = [
            $this->cohort,
            $this->admissionTrustDomain,
            $this->environmentScope,
            $budgetGroup,
            $operation,
            $canonicalSource,
            $account !== null ? '1' : '0',
            $account ?? '',
            $subject !== null ? '1' : '0',
            $subject ?? '',
            $instance !== null ? '1' : '0',
            $instance ?? '',
            $credential !== null ? '1' : '0',
            $credential !== null ? hash('sha256', $credential) : '',
        ];

        return $this->hmacHex(
            $this->admissionStateSecret,
            self::KEY_DOMAIN_PERMIT_COMMITMENT,
            self::MAGIC_PERMIT_COMMITMENT,
            $fields,
            'admission permit commitment',
        );
    }

    /**
     * Derive the opaque store permit-record and counter-member identifier from `(cohort, rawPermitId, ctx)` under
     * `admissionStateSecret`. Neither raw context nor the signed token enters the store key.
     *
     * Logical form: `a1.permit.{64 lowercase hex}`.
     */
    public function permitRecordId(string $rawPermitId, string $contextCommitment): string
    {
        $digest = $this->hmacHex(
            $this->admissionStateSecret,
            self::KEY_DOMAIN_PERMIT_RECORD,
            self::MAGIC_PERMIT_RECORD,
            [$this->cohort, $rawPermitId, $contextCommitment],
            'admission permit record id',
        );

        return 'a1.permit.' . $digest;
    }

    /** Compute the raw 32-byte credential MAC over framed `(cohort, env, authority, 'credential', payloadPart)`. */
    public function credentialMac(string $payloadPart, #[\SensitiveParameter] string $bindingKey): string
    {
        return $this->hmacRaw(
            $bindingKey,
            self::KEY_DOMAIN_CREDENTIAL_MAC,
            self::MAGIC_CREDENTIAL_MAC,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, 'credential', $payloadPart],
            'admission credential mac',
        );
    }

    /** Compute the raw 32-byte permit MAC over framed `(cohort, env, authority, 'permit', payloadPart)`. */
    public function permitMac(string $payloadPart, #[\SensitiveParameter] string $bindingKey): string
    {
        return $this->hmacRaw(
            $bindingKey,
            self::KEY_DOMAIN_PERMIT_MAC,
            self::MAGIC_PERMIT_MAC,
            [$this->cohort, $this->environmentScope, $this->admissionTrustDomain, 'permit', $payloadPart],
            'admission permit mac',
        );
    }

    /** Derive the 16-character lowercase hex key identifier for `$bindingKey` scoped to `(cohort, env, authority)`. */
    public function bindingKeyId(#[\SensitiveParameter] string $bindingKey): string
    {
        return substr(
            $this->hmacHex(
                $bindingKey,
                self::KEY_DOMAIN_KEY_ID,
                self::MAGIC_KEY_ID,
                [$this->cohort, $this->environmentScope, $this->admissionTrustDomain],
                'admission key id',
            ),
            0,
            16,
        );
    }

    /** @param list<string> $fields */
    private function hmacHex(
        #[\SensitiveParameter]
        string $secret,
        string $keyDomain,
        string $magic,
        array $fields,
        string $context,
    ): string {
        $preimage = KeyPreimageFraming::encodeTuple($magic, $fields, $context, $this->length, $this->count);
        $subkey = hash_hmac('sha256', $keyDomain, $secret, true);

        return hash_hmac('sha256', $preimage, $subkey);
    }

    /** @param list<string> $fields */
    private function hmacRaw(
        #[\SensitiveParameter]
        string $secret,
        string $keyDomain,
        string $magic,
        array $fields,
        string $context,
    ): string {
        $preimage = KeyPreimageFraming::encodeTuple($magic, $fields, $context, $this->length, $this->count);
        $subkey = hash_hmac('sha256', $keyDomain, $secret, true);

        return hash_hmac('sha256', $preimage, $subkey, true);
    }
}
