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
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * The admission runtime that a policy declaring `PolicyCapabilityEnum::ADMISSION_RESERVATION` needs (DEC-023, DEC-024,
 * DEC-025): lane resolution from a verified credential, the all-or-nothing reservation at `checkOnly`, the permit, and the
 * outcome hooks. It holds no value of its own: every budget and the permit timing arrive in `AdmissionBudgetsDTO`.
 *
 * Nothing here persists penalties. A capacity refusal is a `SOFT_BLOCK` result that is never stored, never counted by
 * Anti-Equilibrium, budgets or cycles. A failed credential check is the unproven lane, never a refusal and never an
 * exception. Availability failures of the store are the typed `BackendFailureException` and propagate to the engine.
 *
 * @internal Not part of the supported composition: the builder never constructs it, so no policy can opt in.
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
        private readonly AdmissionPermitStoreInterface $store,
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

    public function fenceOpen(string $budgetGroup): bool
    {
        return $this->store->readBindingEpoch(self::fenceKey($this->envScope, $budgetGroup, $this->keySecret), null) >= 1;
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
        if ($credential === null || $credential === '' || $instance === null || $instance === '' || $account === null || $subject === null) {
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
        $scopes = $lane->lane === AdmissionLaneEnum::P
            ? $this->provenScopes($budgetGroup, $context, $device, $lane, $t)
            : $this->unprovenScopes($budgetGroup, $context, $t);
        $id = bin2hex(random_bytes(16));
        $permit = new AdmissionPermitDTO($id, $lane->lane->value, $subject ?? self::NO_SUBJECT, $policyName, $t + $this->budgets->permitDeadlineSeconds, $this->budgets->permitGraceSeconds);
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
        $claim = $this->store->claimAdmissionPermit($payload['id'], $payload['lane']->value, $subject ?? self::NO_SUBJECT, $policyName, $kind, $this->now());
        if ($claim !== AdmissionPermitClaimEnum::ACCEPTED) {
            return $this->permitResult($claim);
        }
        $scopes = $this->outcomeScopes($budgetGroup, $context, $payload, $success);
        if ($scopes !== []) {
            $this->store->applyAdmissionOutcome(new AdmissionOutcomeDTO($budgetGroup, $kind, $scopes, $payload['id']), $this->now());
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
        if ($scope === 'account_bucket') {
            return $storeRetry;
        }
        $day = $scope === 'proof_account_day' || $scope === 'proof_proof_day' || $scope === 'new_device_cap';
        $length = $day ? self::DAY : $this->budgets->windowSeconds;

        return (intdiv($t, $length) + 1) * $length - $t;
    }

    /** @return list<AdmissionScopeDTO> */
    private function unprovenScopes(string $group, RateLimitContextDTO $context, int $t): array
    {
        $b = $this->budgets;
        $window = 'w' . intdiv($t, $b->windowSeconds);
        $source = SourcePrefix::of($context->ip);
        $subject = $this->subject($context);
        $scopes = [$this->counter('source_capacity', $group, 'src', $source, $window, $b->sourceCapacity, 2 * $b->windowSeconds)];
        if ($subject !== null) {
            $scopes[] = $this->failureSet('source_failure_cap', $group, 'ufail', $source, $window, $b->sourceFailureCap, $subject, false);
            $scopes[] = new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, 'acct', $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds);
        }

        return $scopes;
    }

    /** @return list<AdmissionScopeDTO> */
    private function provenScopes(string $group, RateLimitContextDTO $context, DeviceIdentityDTO $device, AdmissionLaneDTO $lane, int $t): array
    {
        $b = $this->budgets;
        $window = 'w' . intdiv($t, $b->windowSeconds);
        $day = 'd' . intdiv($t, self::DAY);
        $subject = $this->subject($context) ?? self::NO_SUBJECT;
        $proof = $this->proofRef($lane->claims->jti ?? $subject);
        $scopes = [
            $this->counter('proof_account_window', $group, 'pacct', $subject, $window, $b->proofAccountWindow, 2 * $b->windowSeconds),
            $this->counter('proof_account_day', $group, 'pacctd', $subject, $day, $b->proofAccountDay, 2 * self::DAY),
            $this->counter('proof_proof_window', $group, 'pproof', $proof, $window, $b->proofProofWindow, 2 * $b->windowSeconds),
            $this->counter('proof_proof_day', $group, 'pproofd', $proof, $day, $b->proofProofDay, 2 * self::DAY),
        ];
        if ($device->fingerprintHash !== null) {
            $scopes[] = $this->deviceMember($group, $subject, $device);
        }
        $scopes[] = $this->failureSet('source_proof_failure_cap', $group, 'pfail', SourcePrefix::of($context->ip), $window, $b->crossAccountFailureCap, $subject, false);

        return $scopes;
    }

    /**
     * @param array{id: string, lane: AdmissionLaneEnum, t0: int, j: ?string} $payload
     * @return list<AdmissionScopeDTO>
     */
    private function outcomeScopes(string $group, RateLimitContextDTO $context, array $payload, bool $success): array
    {
        $b = $this->budgets;
        $t = $payload['t0'];
        $window = 'w' . intdiv($t, $b->windowSeconds);
        $day = 'd' . intdiv($t, self::DAY);
        $subject = $this->subject($context);
        if ($subject === null) {
            return [];
        }
        $source = SourcePrefix::of($context->ip);
        if ($payload['lane'] === AdmissionLaneEnum::P) {
            // D8 neither adds on success nor removes a settled failure: the flag keeps it, and cancels only a pending member.
            $d8 = $this->failureSet('source_proof_failure_cap', $group, 'pfail', $source, $window, $b->crossAccountFailureCap, $subject, true);
            if (! $success) {
                return [$d8];
            }
            $proof = $payload['j'] ?? $subject;

            return [
                $this->counter('proof_account_window', $group, 'pacct', $subject, $window, $b->proofAccountWindow, 2 * $b->windowSeconds),
                $this->counter('proof_account_day', $group, 'pacctd', $subject, $day, $b->proofAccountDay, 2 * self::DAY),
                $this->counter('proof_proof_window', $group, 'pproof', $proof, $window, $b->proofProofWindow, 2 * $b->windowSeconds),
                $this->counter('proof_proof_day', $group, 'pproofd', $proof, $day, $b->proofProofDay, 2 * self::DAY),
                $d8,
            ];
        }
        $scopes = [$this->failureSet('source_failure_cap', $group, 'ufail', $source, $window, $b->sourceFailureCap, $subject, false)];
        if ($success) {
            $scopes[] = new AdmissionScopeDTO('account_bucket', AdmissionScopeKindEnum::BUCKET, $this->key($group, 'acct', $subject, ''), $b->accountBucketTakes, $b->accountBucketSeconds);
        }

        return $scopes;
    }

    private function counter(string $name, string $group, string $purpose, string $value, string $label, int $capacity, int $ttl): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            $name,
            AdmissionScopeKindEnum::COUNTER,
            $this->key($group, $purpose, $value, $label),
            $capacity,
            $ttl,
            $this->previousKeySecret === null ? null : $this->key($group, $purpose, $value, $label, $this->previousKeySecret),
        );
    }

    private function failureSet(string $name, string $group, string $purpose, string $source, string $label, int $capacity, string $subject, bool $keepsSettled): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            $name,
            AdmissionScopeKindEnum::SET_SIZE,
            $this->key($group, $purpose, $source, $label),
            $capacity,
            2 * $this->budgets->windowSeconds,
            null,
            $this->key($group, 'member', $subject, ''),
            null,
            $keepsSettled,
        );
    }

    /** The N_P member is the fingerprint (current and previous generation), never the instance id (NP-IN). */
    private function deviceMember(string $group, string $subject, DeviceIdentityDTO $device): AdmissionScopeDTO
    {
        $previousMember = $this->previousKeySecret !== null && $device->previousFingerprintHash !== null
            ? $this->key($group, 'dev', (string) $device->previousFingerprintHash, '', $this->previousKeySecret)
            : null;

        return new AdmissionScopeDTO(
            'new_device_cap',
            AdmissionScopeKindEnum::DEVICE_MEMBER,
            $this->key($group, 'pdev', $subject, 'set'),
            $this->budgets->newDevicesPerDay,
            self::DAY,
            $this->previousKeySecret === null ? null : $this->key($group, 'pdev', $subject, 'set', $this->previousKeySecret),
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

    /** `correlationId ?? accountId` (LT-4, LT-19). */
    private function subject(RateLimitContextDTO $context): ?string
    {
        return $context->correlationId ?? $context->accountId;
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
