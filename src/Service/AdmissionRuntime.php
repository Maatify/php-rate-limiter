<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialVerificationDTO;
use Maatify\RateLimiter\DTO\AdmissionLaneDTO;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AtomicAdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\RequestAtomicStoreInterface;
use Maatify\RateLimiter\Repository\SharedAdmissionConfigStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * The admission runtime that a policy declaring `PolicyCapabilityEnum::ADMISSION_RESERVATION` needs (DEC-023, DEC-024,
 * DEC-034, DEC-036, DEC-048, DEC-049): lane resolution from a verified v2 cohort-bound credential, the all-or-nothing
 * reservation at `checkOnly`, the context-bound v2 permit, durable account-wide binding epochs, durable shared
 * configuration, and the atomic outcome hooks. It holds no budget value of its own: every budget and the permit timing
 * arrive in `AdmissionBudgetsDTO`.
 *
 * Nothing here persists penalties. A capacity refusal is a `SOFT_BLOCK` result that is never stored, never counted by
 * Anti-Equilibrium, budgets or cycles. A failed credential check is the unproven lane, never a refusal and never an
 * exception. Availability failures of the store are the typed `BackendFailureException` and propagate to the engine.
 *
 * @internal Constructed by `RateLimiterBuilder` with `AdmissionBudgetsDTO::packageDefaults()` when a registered policy
 * declares the capability; not part of the supported public surface.
 */
final class AdmissionRuntime implements OwnedResources
{
    private const DAY = 86400;
    private const TOKEN_VERSION = 2;
    private const PERMIT_PAYLOAD_KEYS = ['v', 'id', 'lane', 't0', 'j', 'ctx'];

    private const CAUSES = [
        'source_capacity' => ['source_capacity', 'source'],
        'source_failure_cap' => ['source_failure_cap', 'source'],
        'account_bucket' => ['account_bucket', 'account'],
        'proof_account_window' => ['proof_capacity', 'proof'],
        'proof_account_day' => ['proof_capacity', 'proof'],
        'proof_proof_window' => ['proof_capacity', 'proof'],
        'proof_proof_day' => ['proof_capacity', 'proof'],
        'new_device_cap' => ['new_device_cap', 'device'],
        'source_proof_failure_cap' => ['source_proof_failure_cap', 'source'],
        'source_pending_permits' => ['source_pending_permits', 'source'],
    ];

    private readonly AdmissionKeyDeriver $keys;

    /**
     * Construct the internal admission runtime for a policy opting into `ADMISSION_RESERVATION`.
     *
     * Requires a non-blank `$keySecret`, an optional non-blank `$previousKeySecret`, and a valid trust context
     * (non-blank `$envScope` and `$admissionTrustDomain`, plus a durable `$admissionStateSecret` of at least 32 bytes
     * under DEC-048) whose derived `cohort()` matches `$codec->cohort()`.
     *
     * @throws RateLimiterException when a key secret is blank, the trust context/state secret is invalid, or `$codec` cohort mismatches.
     */
    public function __construct(
        private readonly AtomicAdmissionPermitStoreInterface $store,
        private readonly AdmissionCredentialCodec $codec,
        private readonly AdmissionBudgetsDTO $budgets,
        private ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $keySecret,
        string $envScope,
        string $admissionTrustDomain,
        #[\SensitiveParameter]
        string $admissionStateSecret,
        #[\SensitiveParameter]
        private readonly ?string $previousKeySecret = null,
    ) {
        $this->clock = HostClock::of($this->clock);
        if (! AdmissionKeyDeriver::isNonBlank($keySecret)) {
            throw new RateLimiterException('Admission runtime requires a non-blank key secret.');
        }
        if ($previousKeySecret !== null && ! AdmissionKeyDeriver::isNonBlank($previousKeySecret)) {
            throw new RateLimiterException('Admission runtime previous key secret must not be blank.');
        }
        $this->keys = new AdmissionKeyDeriver($envScope, $admissionTrustDomain, $admissionStateSecret);
        if (! hash_equals($this->codec->cohort(), $this->keys->cohort())) {
            throw new RateLimiterException('Admission credential codec trust context does not match the admission runtime.');
        }
    }

    /**
     * Run one request of an opted policy (lane, evaluation and reservation) as one atomic save when the store offers it
     * (DEC-037); other stores run it as it is.
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    public function atomically(callable $request): mixed
    {
        return $this->store instanceof RequestAtomicStoreInterface ? $this->store->transactRequest($request) : $request();
    }

    /** The fence reads the current fence key, else the previous one, so an outer key-secret rotation does not close it (DEC-048 D3/D4). */
    public function fenceOpen(string $budgetGroup): bool
    {
        return $this->store->readAdmissionFence(
            $this->keys->fenceKey($budgetGroup, $this->keySecret),
            $this->previousFenceKey($budgetGroup),
        ) >= 1;
    }

    /** Open the fence of a budget group: the current fence key becomes the larger of the current and previous values plus one. */
    public function openFence(string $budgetGroup): int
    {
        return $this->store->bumpAdmissionFence(
            $this->keys->fenceKey($budgetGroup, $this->keySecret),
            $this->previousFenceKey($budgetGroup),
        );
    }

    private function previousFenceKey(string $budgetGroup): ?string
    {
        return $this->previousKeySecret === null ? null : $this->keys->fenceKey($budgetGroup, $this->previousKeySecret);
    }

    /** The effective durable shared configuration of a budget group as this worker holds it (DEC-049), carried by every reservation. */
    private function sharedConfig(string $budgetGroup): AdmissionSharedConfigDTO
    {
        return AdmissionSharedConfigDTO::forLimit(
            $this->keys->configKey($budgetGroup),
            $this->budgets->provenPendingPermitCap,
        );
    }

    /**
     * Register this worker's effective shared configuration for `$budgetGroup` as the value every worker must hold (DEC-049).
     * An explicit operator action; no request path calls it.
     *
     * @throws AdmissionConfigurationException when the store cannot hold a shared configuration
     */
    public function rebindSharedConfig(string $budgetGroup): void
    {
        if (! $this->store instanceof SharedAdmissionConfigStoreInterface) {
            throw new AdmissionConfigurationException('The configured store cannot hold a shared admission configuration.');
        }
        $this->store->rebindAdmissionConfig($budgetGroup, $this->sharedConfig($budgetGroup));
    }

    /** Read the account-wide durable binding epoch (DEC-048 D3/D4: independent of outer key-secret rotation). */
    public function bindingEpoch(string $accountId): int
    {
        return $this->store->readBindingEpoch($this->keys->epochKey($accountId));
    }

    /** Atomically bump the account-wide durable binding epoch (DEC-048 D3/D4). */
    public function revokeAccountBinding(string $accountId): void
    {
        $this->store->bumpBindingEpoch($this->keys->epochKey($accountId));
    }

    /**
     * Any failed check is the unproven lane. A credential is only looked at when the request carries a credential, an
     * instance id and an account (so the epoch is not read for a request that cannot be proven). Both the explicit
     * account claim and the effective correlation subject must match the signed credential (DEC-048 D5).
     */
    public function resolveLane(string $budgetGroup, RateLimitContextDTO $context): AdmissionLaneDTO
    {
        $credential = $context->admissionCredential;
        $instance = $context->clientInstanceId;
        $account = $context->accountId;
        $subject = $this->subject($context);
        if ($credential === null || $credential === '' || $instance === null || $instance === '' || ! IdentityPresence::isPresent($account) || $subject === null) {
            return new AdmissionLaneDTO(AdmissionLaneEnum::U);
        }
        $verdict = $this->codec->verify($credential, $account, $subject, $budgetGroup, $instance, $this->now(), $this->bindingEpoch($account));
        $lane = $this->laneFromVerdict($verdict, $context);

        return new AdmissionLaneDTO($lane, $lane === AdmissionLaneEnum::P ? $verdict->claims : null);
    }

    /** Only a verified credential selects the proven lane; no flag, header, id or known-device value ever does. */
    private function laneFromVerdict(AdmissionCredentialVerificationDTO $verdict, RateLimitContextDTO $context): AdmissionLaneEnum
    {
        return $verdict->isValid() ? AdmissionLaneEnum::P : AdmissionLaneEnum::U;
    }

    /**
     * Reserve all the scopes of the request's door at once. Returns the allowed result carrying the context-bound v2
     * permit, or the capacity refusal. Nothing is written by a refusal.
     */
    public function reserve(
        string $policyName,
        string $budgetGroup,
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        AdmissionLaneDTO $lane,
        RateLimitResultDTO $allowed,
    ): RateLimitResultDTO {
        $t = $this->now();
        $rawId = bin2hex(random_bytes(16));
        // `$t + D` is the permit deadline, a native `int`: a sum that overflows (on 32-bit PHP after 2^31 - 1) would become a
        // float and reach the strict-typed DTO as a TypeError, so an unrepresentable deadline is rejected as a typed input failure.
        if ($this->budgets->permitDeadlineSeconds > PHP_INT_MAX - $t) {
            throw new RateLimiterException('Admission permit deadline is not representable as a native integer.');
        }
        $ctx = $this->keys->permitContextCommitment($budgetGroup, $policyName, $context);
        $recordId = $this->keys->permitRecordId($rawId, $ctx);
        $permit = new AdmissionPermitDTO($recordId, $lane->lane->value, $ctx, $policyName, $t + $this->budgets->permitDeadlineSeconds, $this->budgets->permitGraceSeconds);
        $scopes = $lane->lane === AdmissionLaneEnum::P
            ? $this->provenScopes($budgetGroup, $context, $device, $lane, $recordId)
            : $this->unprovenScopes($budgetGroup, $context, $recordId);
        $result = $this->store->reserveAdmission(new AdmissionReservationRequestDTO($budgetGroup, $scopes, $permit, $this->sharedConfig($budgetGroup)), $t);
        if (! $result->admitted) {
            $scope = (string) $result->refusedScope;
            if (! isset(self::CAUSES[$scope])) {
                throw new RateLimiterException('Admission store refused with an unknown scope.');
            }

            return $this->refusalResult($scope, $this->retryAfter($scope, $t, $result->retryAfterSeconds));
        }
        $token = $this->codec->signPermitToken(json_encode([
            'v' => self::TOKEN_VERSION,
            'id' => $rawId,
            'lane' => $lane->lane->value,
            't0' => $t,
            'j' => $lane->claims === null ? null : $this->proofRef($lane->claims->jti),
            'ctx' => $ctx,
        ], JSON_THROW_ON_ERROR));
        $meta = $allowed->metadata;

        return new RateLimitResultDTO(
            $allowed->decision,
            $allowed->blockLevel,
            $allowed->retryAfter,
            $allowed->failureMode,
            new RateLimitMetadataDTO($meta?->signal, $meta?->cause, $meta?->context, $meta?->postPunishmentReentry, $token),
        );
    }

    /**
     * Verify the v2 permit and its context commitment before touching the store, then atomically claim the permit with
     * the outcome (DEC-048 D6). Returns the lane of the admission when the claim is accepted (the store has applied the
     * outcome and the caller scores it exactly once), or the non-applied result of the claim (no scoring, no exception).
     */
    public function applyOutcome(
        string $policyName,
        string $budgetGroup,
        RateLimitContextDTO $context,
        string $token,
        bool $success,
    ): AdmissionLaneEnum|RateLimitResultDTO {
        $payload = $this->decodeToken($token);
        if ($payload === null) {
            return $this->permitResult(AdmissionPermitClaimEnum::UNKNOWN);
        }
        $expectedCtx = $this->keys->permitContextCommitment($budgetGroup, $policyName, $context);
        if (! hash_equals($payload['ctx'], $expectedCtx)) {
            return $this->permitResult(AdmissionPermitClaimEnum::REJECTED);
        }
        $recordId = $this->keys->permitRecordId($payload['id'], $expectedCtx);
        $kind = $success ? AdmissionOutcomeKindEnum::SUCCESS : AdmissionOutcomeKindEnum::FAILURE;

        $scopes = $this->outcomeScopes($budgetGroup, $context, $payload, $recordId, $success);
        $crossAccount = $this->crossAccountFailure($budgetGroup, $context, $payload, $success);
        if ($crossAccount !== null) {
            $scopes[] = $crossAccount;
        }

        $outcome = new AdmissionOutcomeDTO($budgetGroup, $kind, $scopes, $recordId);
        $claim = $this->store->claimAdmissionPermitWithOutcome(
            $payload['lane']->value,
            $expectedCtx,
            $policyName,
            $outcome,
            $this->now(),
        );

        if ($claim !== AdmissionPermitClaimEnum::ACCEPTED) {
            return $this->permitResult($claim);
        }

        return $payload['lane'];
    }

    private function refusalResult(string $scope, int $retryAfter): RateLimitResultDTO
    {
        [$cause, $public] = self::CAUSES[$scope];

        return new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_SOFT_BLOCK,
            1,
            max(1, $retryAfter),
            'NORMAL',
            new RateLimitMetadataDTO(null, $cause, new RateLimitContextMetadataDTO($cause, $public)),
        );
    }

    private function permitResult(AdmissionPermitClaimEnum $claim): RateLimitResultDTO
    {
        $cause = 'permit_' . $claim->value;

        return new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_ALLOW,
            0,
            0,
            'NORMAL',
            new RateLimitMetadataDTO(null, $cause, new RateLimitContextMetadataDTO($cause, 'permit')),
        );
    }

    private function retryAfter(string $scope, int $t, int $storeRetry): int
    {
        return max(1, $storeRetry);
    }

    /** @return list<AdmissionScopeDTO> */
    private function unprovenScopes(string $group, RateLimitContextDTO $context, string $recordId): array
    {
        $b = $this->budgets;
        $source = SourcePrefix::of($context->ip);
        $subject = $this->subject($context);
        $scopes = [$this->counter('source_capacity', $group, AdmissionScopePurpose::SRC, $source, $b->sourceCapacity, $b->windowSeconds, $recordId)];
        if ($subject !== null) {
            // The study's order (LT-25): the net failure set, then the account bucket, then the source counter. The first
            // refusing scope names the cause.
            $scopes = [
                $this->failureSet('source_failure_cap', $group, AdmissionScopePurpose::UFAIL, $source, $b->sourceFailureCap, $subject),
                new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, AdmissionScopePurpose::ACCT, $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds),
                ...$scopes,
            ];
        }

        return $scopes;
    }

    /** @return list<AdmissionScopeDTO> */
    private function provenScopes(string $group, RateLimitContextDTO $context, DeviceIdentityDTO $device, AdmissionLaneDTO $lane, string $recordId): array
    {
        $b = $this->budgets;
        $subject = $this->subject($context);
        if ($subject === null) {
            throw new RateLimiterException('Proven admission reservation requires a subject.');
        }
        $proof = $this->proofRef($lane->claims->jti ?? $subject);
        $scopes = [
            $this->counter('proof_account_window', $group, AdmissionScopePurpose::PACCT, $subject, $b->proofAccountWindow, $b->windowSeconds, $recordId),
            $this->counter('proof_account_day', $group, AdmissionScopePurpose::PACCTD, $subject, $b->proofAccountDay, self::DAY, $recordId),
            $this->counter('proof_proof_window', $group, AdmissionScopePurpose::PPROOF, $proof, $b->proofProofWindow, $b->windowSeconds, $recordId),
            $this->counter('proof_proof_day', $group, AdmissionScopePurpose::PPROOFD, $proof, $b->proofProofDay, self::DAY, $recordId),
        ];
        if (IdentityPresence::fingerprint($device)) {
            $scopes[] = $this->deviceMember($group, $subject, $device);
        }
        $scopes[] = $this->failureSet('source_proof_failure_cap', $group, AdmissionScopePurpose::PFAIL, SourcePrefix::of($context->ip), $b->crossAccountFailureCap, $subject);
        $pending = $this->pendingPermitScope($group, $context, $recordId);
        if ($pending !== null) {
            $scopes[] = $pending;
        }

        return $scopes;
    }

    /**
     * The pending-permit slot of the source on the proven lane (#206, DEC-049), null when no cap is configured. A counter whose
     * member is the derived permit record id and whose lifetime is the permit deadline `D`, so an unclaimed permit frees its
     * slot when it expires. Its key is its own purpose (`ppend`): it shares nothing with the failure sets.
     */
    private function pendingPermitScope(string $group, RateLimitContextDTO $context, string $recordId): ?AdmissionScopeDTO
    {
        $cap = $this->budgets->provenPendingPermitCap;
        if ($cap === null) {
            return null;
        }
        $sourceKey = SourcePrefix::of($context->ip);

        return new AdmissionScopeDTO(
            'source_pending_permits',
            AdmissionScopeKindEnum::COUNTER,
            $this->key($group, AdmissionScopePurpose::PPEND, $sourceKey, ''),
            $cap,
            $this->budgets->permitDeadlineSeconds,
            $this->previousKeySecret === null ? null : $this->key($group, AdmissionScopePurpose::PPEND, $sourceKey, '', $this->previousKeySecret),
            $recordId,
            null,
            false,
            false,
            true,
        );
    }

    /**
     * The scopes that free the pending-permit slot of an accepted outcome, whichever outcome it is (#206, DEC-049).
     *
     * Invariant: release once per distinct derived key. A permit reserved before an outer key-secret rotation holds its
     * member under the previous outer secret's derived key, where the current-generation scope does not look; when
     * `previousKey` is null or aliases `current->key` (for example identical or HMAC-equivalent NUL-padded secrets),
     * only the current scope is returned so PHP-side `assertScopes()` before `EVAL` never rejects a duplicate key.
     *
     * @return list<AdmissionScopeDTO>
     */
    private function pendingPermitRelease(string $group, RateLimitContextDTO $context, string $recordId): array
    {
        $current = $this->pendingPermitScope($group, $context, $recordId);
        if ($current === null) {
            return [];
        }
        $previousKey = $current->previousKey;
        if ($previousKey === null || $previousKey === $current->key) {
            return [$current];
        }

        return [
            $current,
            new AdmissionScopeDTO(
                $current->name,
                AdmissionScopeKindEnum::COUNTER,
                $previousKey,
                $current->capacity,
                $current->ttlSeconds,
                null,
                $recordId,
                null,
                false,
                false,
                true,
            ),
        ];
    }

    /**
     * The scopes an accepted outcome applies with its own kind. U: the net failure set (a failure adds the subject,
     * a success removes it) and, on a success, the bucket refund. P: on a success the refund of the proof counters;
     * a failure refunds nothing (its D8 entry is `crossAccountFailure()`).
     *
     * @param array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string, ctx: string} $payload
     * @return list<AdmissionScopeDTO>
     */
    private function outcomeScopes(string $group, RateLimitContextDTO $context, array $payload, string $recordId, bool $success): array
    {
        $b = $this->budgets;
        $subject = $this->subject($context);
        if ($subject === null) {
            return [];
        }
        if ($payload['lane'] === AdmissionLaneEnum::P) {
            $scopes = $success ? $this->proofRefund($group, $subject, $payload['j'] ?? $subject, $recordId) : [];

            return [...$scopes, ...$this->pendingPermitRelease($group, $context, $recordId)];
        }
        $scopes = [$this->failureSet('source_failure_cap', $group, AdmissionScopePurpose::UFAIL, SourcePrefix::of($context->ip), $b->sourceFailureCap, $subject)];
        if ($success) {
            $scopes[] = new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, AdmissionScopePurpose::ACCT, $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds);
        }

        return $scopes;
    }

    /**
     * The four proof counters that a success on the proven door refunds (the reservation's).
     *
     * @return list<AdmissionScopeDTO>
     */
    private function proofRefund(string $group, string $subject, string $proof, string $recordId): array
    {
        $b = $this->budgets;

        return [
            $this->counter('proof_account_window', $group, AdmissionScopePurpose::PACCT, $subject, $b->proofAccountWindow, $b->windowSeconds, $recordId),
            $this->counter('proof_account_day', $group, AdmissionScopePurpose::PACCTD, $subject, $b->proofAccountDay, self::DAY, $recordId),
            $this->counter('proof_proof_window', $group, AdmissionScopePurpose::PPROOF, $proof, $b->proofProofWindow, $b->windowSeconds, $recordId),
            $this->counter('proof_proof_day', $group, AdmissionScopePurpose::PPROOFD, $proof, $b->proofProofDay, self::DAY, $recordId),
        ];
    }

    /**
     * The D8 set an accepted outcome on the proven door enters, applied as a failure, else null. A success never sends it: with
     * the store default a success on a set-size scope removes the member, which would erase a recorded D8 failure; D8 is never
     * refunded and never reduced by a success.
     *
     * @param array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string, ctx: string} $payload
     */
    private function crossAccountFailure(string $group, RateLimitContextDTO $context, array $payload, bool $success): ?AdmissionScopeDTO
    {
        $subject = $this->subject($context);
        if ($payload['lane'] !== AdmissionLaneEnum::P || $subject === null || ! $this->entersCrossAccountSet($success)) {
            return null;
        }

        return $this->failureSet('source_proof_failure_cap', $group, AdmissionScopePurpose::PFAIL, SourcePrefix::of($context->ip), $this->budgets->crossAccountFailureCap, $subject);
    }

    /** Whether an accepted outcome on the proven door enters the D8 set: a failure does, a success never (DEC-034 8.2). */
    private function entersCrossAccountSet(bool $success): bool
    {
        return ! $success;
    }

    private function counter(string $name, string $group, string $purpose, string $value, int $capacity, int $ttl, ?string $memberId = null): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            $name,
            AdmissionScopeKindEnum::COUNTER,
            $this->key($group, $purpose, $value, ''),
            $capacity,
            $ttl,
            $this->previousKeySecret === null ? null : $this->key($group, $purpose, $value, '', $this->previousKeySecret),
            $memberId,
        );
    }

    /** A failure set (U `F`, P D8) at the store default: no settlement, no `successKeepsSettled` (PLAN §11.2). */
    private function failureSet(string $name, string $group, string $purpose, string $source, int $capacity, string $subject): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            $name,
            AdmissionScopeKindEnum::SET_SIZE,
            $this->key($group, $purpose, $source, ''),
            $capacity,
            $this->budgets->windowSeconds,
            null,
            $this->key($group, AdmissionScopePurpose::MEMBER, $subject, ''),
        );
    }

    /** The N_P member is the fingerprint (current and previous generation), never the instance id (NP-IN). */
    private function deviceMember(string $group, string $subject, DeviceIdentityDTO $device): AdmissionScopeDTO
    {
        $hasPrevKey = $this->previousKeySecret !== null;
        $hasPrevFp = $device->previousFingerprintHash !== null;

        $previousMember = null;
        if ($hasPrevKey || $hasPrevFp) {
            $prevFpHash = $hasPrevFp ? (string) $device->previousFingerprintHash : (string) $device->fingerprintHash;
            $prevSecret = $hasPrevKey ? $this->previousKeySecret : $this->keySecret;
            $previousMember = $this->key($group, AdmissionScopePurpose::DEV, $prevFpHash, '', $prevSecret);
        }

        $previousKey = $hasPrevKey
            ? $this->key($group, AdmissionScopePurpose::PDEV, $subject, 'set', $this->previousKeySecret)
            : null;

        return new AdmissionScopeDTO(
            'new_device_cap',
            AdmissionScopeKindEnum::DEVICE_MEMBER,
            $this->key($group, AdmissionScopePurpose::PDEV, $subject, 'set'),
            $this->budgets->newDevicesPerDay,
            self::DAY,
            $previousKey,
            $this->key($group, AdmissionScopePurpose::DEV, (string) $device->fingerprintHash, ''),
            $previousMember,
        );
    }

    /** A bounded, opaque reference of the credential id: the permit token stays short whatever the Host puts in `jti`. */
    private function proofRef(string $jti): string
    {
        return substr(hash('sha256', $jti), 0, 32);
    }

    private function key(string $group, string $purpose, string $value, string $label, #[\SensitiveParameter] ?string $secret = null): string
    {
        return $this->keys->scopeKey($group, $purpose, $value, $label, $secret ?? $this->keySecret);
    }

    /** `correlationId` when present, else the account identifier when present (LT-4, LT-19; #185 F-185-04). */
    private function subject(RateLimitContextDTO $context): ?string
    {
        return IdentityPresence::subject($context);
    }

    /** @return array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string, ctx: string}|null */
    private function decodeToken(string $token): ?array
    {
        $json = $this->codec->verifyPermitToken($token);
        if ($json === null) {
            return null;
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || array_keys($data) !== self::PERMIT_PAYLOAD_KEYS
            || $data['v'] !== self::TOKEN_VERSION
            || ! is_string($data['id']) || ! AdmissionKeyDeriver::isNonBlank($data['id'])
            || ! is_string($data['lane'])
            || ! is_int($data['t0']) || $data['t0'] < 0
            || ! is_string($data['ctx']) || preg_match('/\A[0-9a-f]{64}\z/D', $data['ctx']) !== 1) {
            return null;
        }
        $proof = $data['j'];
        $lane = AdmissionLaneEnum::tryFrom($data['lane']);
        if ($lane === null || ($proof !== null && ! is_string($proof))) {
            return null;
        }

        return ['id' => $data['id'], 'lane' => $lane, 't0' => $data['t0'], 'j' => $proof, 'ctx' => $data['ctx']];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** @return list<object> */
    public function ownedResources(): array
    {
        return OwnershipRegistry::collect([$this->store], [], [$this->clock]);
    }
}
