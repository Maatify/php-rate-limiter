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
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AtomicAdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\RequestAtomicStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * The admission runtime that a policy declaring `PolicyCapabilityEnum::ADMISSION_RESERVATION` needs (DEC-023, DEC-024,
 * DEC-025, DEC-036): lane resolution from a verified credential, the all-or-nothing reservation at `checkOnly`, the permit, and the
 * outcome hooks. It holds no value of its own: every budget and the permit timing arrive in `AdmissionBudgetsDTO`.
 *
 * Nothing here persists penalties. A capacity refusal is a `SOFT_BLOCK` result that is never stored, never counted by
 * Anti-Equilibrium, budgets or cycles. A failed credential check is the unproven lane, never a refusal and never an
 * exception. Availability failures of the store are the typed `BackendFailureException` and propagate to the engine.
 *
 * @internal Constructed by `RateLimiterBuilder` with `AdmissionBudgetsDTO::packageDefaults()` when a registered policy
 * declares the capability; not part of the supported public surface.
 */
final class AdmissionRuntime
{
    private const DAY = 86400;
    private const TOKEN_VERSION = 1;
    private const NO_SUBJECT = '-';

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
    ];

    public function __construct(
        private readonly AtomicAdmissionPermitStoreInterface $store,
        private readonly AdmissionCredentialCodec $codec,
        private readonly AdmissionBudgetsDTO $budgets,
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $keySecret,
        private readonly string $envScope,
        #[\SensitiveParameter]
        private readonly ?string $previousKeySecret = null,
    ) {
        if (trim($keySecret) === '' || trim($envScope) === '') {
            throw new RateLimiterException('Admission runtime requires a non-blank key secret and environment scope.');
        }
        if ($previousKeySecret !== null && trim($previousKeySecret) === '') {
            throw new RateLimiterException('Admission runtime previous key secret must not be blank.');
        }
    }

    /** The opaque key of the fleet fence of one budget group (LT-13): an integer in the epoch namespace, enabled when at least 1. */
    public static function fenceKey(string $envScope, string $budgetGroup, string $keySecret): string
    {
        return hash_hmac('sha256', implode("\0", [$envScope, 'admission_fence', $budgetGroup]), $keySecret);
    }

    /** The fence reads the current key, else the previous one, so a key-secret rotation does not close it (LT-27 (3)). */
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

    public function fenceOpen(string $budgetGroup): bool
    {
        return $this->store->readBindingEpoch(self::fenceKey($this->envScope, $budgetGroup, $this->keySecret), $this->previousFenceKey($budgetGroup)) >= 1;
    }

    /** Open the fence of a budget group: the current key becomes the larger of the current and previous values plus one. */
    public function openFence(string $budgetGroup): int
    {
        return $this->store->bumpBindingEpoch(self::fenceKey($this->envScope, $budgetGroup, $this->keySecret), $this->previousFenceKey($budgetGroup));
    }

    private function previousFenceKey(string $budgetGroup): ?string
    {
        return $this->previousKeySecret === null ? null : self::fenceKey($this->envScope, $budgetGroup, $this->previousKeySecret);
    }

    public function bindingEpoch(string $accountId): int
    {
        return $this->store->readBindingEpoch($this->epochKey($accountId, $this->keySecret), $this->previousEpochKey($accountId));
    }

    public function revokeAccountBinding(string $accountId): void
    {
        $this->store->bumpBindingEpoch($this->epochKey($accountId, $this->keySecret), $this->previousEpochKey($accountId));
    }

    /**
     * Any failed check is the unproven lane. A credential is only looked at when the request carries a credential, an
     * instance id and an account (so the epoch is not read for a request that cannot be proven).
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
        $verdict = $this->codec->verify($credential, $subject, $budgetGroup, $instance, $this->now(), $this->bindingEpoch($account));
        $lane = $this->laneFromVerdict($verdict, $context);

        return new AdmissionLaneDTO($lane, $lane === AdmissionLaneEnum::P ? $verdict->claims : null);
    }

    /** Only a verified credential selects the proven lane; no flag, header, id or known-device value ever does. */
    private function laneFromVerdict(AdmissionCredentialVerificationDTO $verdict, RateLimitContextDTO $context): AdmissionLaneEnum
    {
        return $verdict->isValid() ? AdmissionLaneEnum::P : AdmissionLaneEnum::U;
    }

    /**
     * Reserve all the scopes of the request's door at once. Returns the allowed result carrying the permit, or the capacity
     * refusal. Nothing is written by a refusal.
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
        $subject = $this->subject($context);
        $id = bin2hex(random_bytes(16));
        $permit = new AdmissionPermitDTO($id, $lane->lane->value, $subject ?? self::NO_SUBJECT, $policyName, $t + $this->budgets->permitDeadlineSeconds, $this->budgets->permitGraceSeconds);
        $scopes = $lane->lane === AdmissionLaneEnum::P
            ? $this->provenScopes($budgetGroup, $context, $device, $lane, $id)
            : $this->unprovenScopes($budgetGroup, $context, $id);
        $result = $this->store->reserveAdmission(new AdmissionReservationRequestDTO($budgetGroup, $scopes, $permit), $t);
        if (! $result->admitted) {
            $scope = (string) $result->refusedScope;
            if (! isset(self::CAUSES[$scope])) {
                throw new RateLimiterException('Admission store refused with an unknown scope.');
            }

            return $this->refusalResult($scope, $this->retryAfter($scope, $t, $result->retryAfterSeconds));
        }
        $token = $this->codec->signPermitToken(json_encode([
            'v' => self::TOKEN_VERSION,
            'id' => $id,
            'lane' => $lane->lane->value,
            't0' => $t,
            'j' => $lane->claims === null ? null : $this->proofRef($lane->claims->jti),
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
     * Claim the permit with the outcome. Returns the lane of the admission when the claim is accepted (the store has applied
     * the outcome and the caller scores it exactly once), or the non-applied result of the claim (no scoring, no exception).
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
        $kind = $success ? AdmissionOutcomeKindEnum::SUCCESS : AdmissionOutcomeKindEnum::FAILURE;
        $subject = $this->subject($context);

        $scopes = $this->outcomeScopes($budgetGroup, $context, $payload, $success);
        $crossAccount = $this->crossAccountFailure($budgetGroup, $context, $payload, $success);
        if ($crossAccount !== null) {
            $scopes[] = $crossAccount;
        }

        $outcome = new AdmissionOutcomeDTO($budgetGroup, $kind, $scopes, $payload['id']);
        $claim = $this->store->claimAdmissionPermitWithOutcome(
            $payload['lane']->value,
            $subject ?? self::NO_SUBJECT,
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
    private function unprovenScopes(string $group, RateLimitContextDTO $context, string $permitId): array
    {
        $b = $this->budgets;
        $source = SourcePrefix::of($context->ip);
        $subject = $this->subject($context);
        $scopes = [$this->counter('source_capacity', $group, 'src', $source, $b->sourceCapacity, $b->windowSeconds, $permitId)];
        if ($subject !== null) {
            // The study's order (LT-25): the net failure set, then the account bucket, then the source counter. The first
            // refusing scope names the cause.
            $scopes = [
                $this->failureSet('source_failure_cap', $group, 'ufail', $source, $b->sourceFailureCap, $subject),
                new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, 'acct', $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds),
                ...$scopes,
            ];
        }

        return $scopes;
    }

    /** @return list<AdmissionScopeDTO> */
    private function provenScopes(string $group, RateLimitContextDTO $context, DeviceIdentityDTO $device, AdmissionLaneDTO $lane, string $permitId): array
    {
        $b = $this->budgets;
        $subject = $this->subject($context) ?? self::NO_SUBJECT;
        $proof = $this->proofRef($lane->claims->jti ?? $subject);
        $scopes = [
            $this->counter('proof_account_window', $group, 'pacct', $subject, $b->proofAccountWindow, $b->windowSeconds, $permitId),
            $this->counter('proof_account_day', $group, 'pacctd', $subject, $b->proofAccountDay, self::DAY, $permitId),
            $this->counter('proof_proof_window', $group, 'pproof', $proof, $b->proofProofWindow, $b->windowSeconds, $permitId),
            $this->counter('proof_proof_day', $group, 'pproofd', $proof, $b->proofProofDay, self::DAY, $permitId),
        ];
        if (IdentityPresence::fingerprint($device)) {
            $scopes[] = $this->deviceMember($group, $subject, $device);
        }
        $scopes[] = $this->failureSet('source_proof_failure_cap', $group, 'pfail', SourcePrefix::of($context->ip), $b->crossAccountFailureCap, $subject);

        return $scopes;
    }

    /**
     * The scopes an accepted outcome applies with its own kind. U: the net failure set (a failure adds the subject,
     * a success removes it) and, on a success, the bucket refund. P: on a success the refund of the proof counters;
     * a failure refunds nothing (its D8 entry is `crossAccountFailure()`).
     *
     * @param array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string} $payload
     * @return list<AdmissionScopeDTO>
     */
    private function outcomeScopes(string $group, RateLimitContextDTO $context, array $payload, bool $success): array
    {
        $b = $this->budgets;
        $subject = $this->subject($context);
        if ($subject === null) {
            return [];
        }
        if ($payload['lane'] === AdmissionLaneEnum::P) {
            return $success ? $this->proofRefund($group, $subject, $payload['j'] ?? $subject, $payload['id']) : [];
        }
        $scopes = [$this->failureSet('source_failure_cap', $group, 'ufail', SourcePrefix::of($context->ip), $b->sourceFailureCap, $subject)];
        if ($success) {
            $scopes[] = new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, 'acct', $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds);
        }

        return $scopes;
    }

    /**
     * The four proof counters that a success on the proven door refunds (the reservation's).
     *
     * @return list<AdmissionScopeDTO>
     */
    private function proofRefund(string $group, string $subject, string $proof, string $permitId): array
    {
        $b = $this->budgets;

        return [
            $this->counter('proof_account_window', $group, 'pacct', $subject, $b->proofAccountWindow, $b->windowSeconds, $permitId),
            $this->counter('proof_account_day', $group, 'pacctd', $subject, $b->proofAccountDay, self::DAY, $permitId),
            $this->counter('proof_proof_window', $group, 'pproof', $proof, $b->proofProofWindow, $b->windowSeconds, $permitId),
            $this->counter('proof_proof_day', $group, 'pproofd', $proof, $b->proofProofDay, self::DAY, $permitId),
        ];
    }

    /**
     * The D8 set an accepted outcome on the proven door enters, applied as a failure, else null. A success never sends it: with
     * the store default a success on a set-size scope removes the member, which would erase a recorded D8 failure; D8 is never
     * refunded and never reduced by a success.
     *
     * @param array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string} $payload
     */
    private function crossAccountFailure(string $group, RateLimitContextDTO $context, array $payload, bool $success): ?AdmissionScopeDTO
    {
        $subject = $this->subject($context);
        if ($payload['lane'] !== AdmissionLaneEnum::P || $subject === null || ! $this->entersCrossAccountSet($success)) {
            return null;
        }

        return $this->failureSet('source_proof_failure_cap', $group, 'pfail', SourcePrefix::of($context->ip), $this->budgets->crossAccountFailureCap, $subject);
    }

    /** Whether an accepted outcome on the proven door enters the D8 set: a failure does, a success never (DEC-025 8.2). */
    private function entersCrossAccountSet(bool $success): bool
    {
        return ! $success;
    }

    private function counter(string $name, string $group, string $purpose, string $value, int $capacity, int $ttl, ?string $permitId = null): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            $name,
            AdmissionScopeKindEnum::COUNTER,
            $this->key($group, $purpose, $value, ''),
            $capacity,
            $ttl,
            $this->previousKeySecret === null ? null : $this->key($group, $purpose, $value, '', $this->previousKeySecret),
            $permitId,
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
            $this->key($group, 'member', $subject, ''),
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
            $previousMember = $this->key($group, 'dev', $prevFpHash, '', $prevSecret);
        }

        $previousKey = $hasPrevKey
            ? $this->key($group, 'pdev', $subject, 'set', $this->previousKeySecret)
            : null;

        return new AdmissionScopeDTO(
            'new_device_cap',
            AdmissionScopeKindEnum::DEVICE_MEMBER,
            $this->key($group, 'pdev', $subject, 'set'),
            $this->budgets->newDevicesPerDay,
            self::DAY,
            $previousKey,
            $this->key($group, 'dev', (string) $device->fingerprintHash, ''),
            $previousMember,
        );
    }

    /** A bounded, opaque reference of the credential id: the permit token stays short whatever the Host puts in `jti`. */
    private function proofRef(string $jti): string
    {
        return substr(hash('sha256', $jti), 0, 32);
    }

    private function key(string $group, string $purpose, string $value, string $label, ?string $secret = null): string
    {
        return $purpose . ':' . hash_hmac('sha256', implode("\0", [$this->envScope, $group, $purpose, $value, $label]), $secret ?? $this->keySecret);
    }

    private function epochKey(string $accountId, string $secret): string
    {
        return hash_hmac('sha256', implode("\0", [$this->envScope, 'binding_epoch', $accountId]), $secret);
    }

    private function previousEpochKey(string $accountId): ?string
    {
        return $this->previousKeySecret === null ? null : $this->epochKey($accountId, $this->previousKeySecret);
    }

    /** `correlationId` when present, else the account identifier when present (LT-4, LT-19; #185 F-185-04). */
    private function subject(RateLimitContextDTO $context): ?string
    {
        return IdentityPresence::subject($context);
    }

    /** @return array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string}|null */
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
        if (! is_array($data) || ($data['v'] ?? null) !== self::TOKEN_VERSION || ! is_string($data['id'] ?? null) || $data['id'] === ''
            || ! is_string($data['lane'] ?? null) || ! is_int($data['t0'] ?? null)) {
            return null;
        }
        $proof = $data['j'] ?? null;
        $lane = AdmissionLaneEnum::tryFrom($data['lane']);
        if ($lane === null || ($proof !== null && ! is_string($proof))) {
            return null;
        }

        return ['id' => $data['id'], 'lane' => $lane, 't0' => $data['t0'], 'j' => $proof];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
