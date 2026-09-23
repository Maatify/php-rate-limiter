<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\PipelineScoreDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\PenaltyLadder;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Executes key derivation, score evaluation, budget handling, and aggregation.
 *
 * The pipeline reads both the active and previous key generation when rotation
 * is configured, while writing only to the active generation.
 */
class EvaluationPipeline
{
    private const BUDGET_EPOCH_SECONDS = 86400; // 24h
    private const IPV6_ADAPTIVE_WINDOW_SECONDS = 600;
    private const IPV6_ADAPTIVE_48_CAP = 2;
    private const IPV6_ADAPTIVE_40_CAP = 4;
    private const IPV6_ADAPTIVE_32_CAP = 8;

    private string $secret;
    private ?string $previousSecret;

    /**
     * @param RateLimitStoreInterface $store Score and block persistence boundary.
     * @param CorrelationStoreInterface $correlationStore Correlation persistence boundary.
     * @param BudgetTracker $budgetTracker Account-budget service.
     * @param AntiEquilibriumGate $antiEquilibriumGate Repeated-soft-block guard.
     * @param DecayCalculator $decayCalculator Score decay service.
     * @param EphemeralBucket $ephemeralBucket Device-cap key resolver.
     * @param string $keySecret Active key-generation secret.
     * @param string $envScope Environment namespace included in derived keys.
     * @param ClockInterface $clock Source of current timestamps.
     * @param ?string $previousKeySecret Optional previous-generation secret.
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly CorrelationStoreInterface $correlationStore,
        private readonly BudgetTracker $budgetTracker,
        private readonly AntiEquilibriumGate $antiEquilibriumGate,
        private readonly DecayCalculator $decayCalculator,
        private readonly EphemeralBucket $ephemeralBucket,
        string $keySecret,
        private readonly string $envScope, // e.g. 'prod', 'staging'
        private readonly ClockInterface $clock,
        ?string $previousKeySecret = null,
    ) {
        $this->secret = $keySecret;
        $this->previousSecret = $previousKeySecret;
    }

    /**
     * Run the read-only backend health boundary used by circuit-breaker recovery.
     *
     * This method delegates only to RateLimitStoreInterface::isHealthy(). It does
     * not resolve identity, read or write scores, evaluate blocks, touch budgets,
     * observe correlation state, or execute normal pipeline processing.
     */
    public function isBackendHealthy(): bool
    {
        return $this->store->isHealthy();
    }

    /**
     * Evaluate a command and return the winning allow or block decision.
     *
     * Normal candidates are aggregated before budget enforcement, and only
     * winning candidates are persisted as blocks.
     */
    public function process(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
    ): RateLimitResultDTO {
        // 1. Build keys for the current generation, then the one previous
        // generation when either generation component is present.
        $realKeysV2 = $this->buildKeys($context, $device->normalizedUa, $device->fingerprintHash, $policy->getName(), $this->secret);
        $realKeysV1 = $this->hasPreviousGeneration($device)
            ? $this->buildKeys(
                $context,
                $device->normalizedUa,
                $this->previousFingerprintHash($device),
                $policy->getName(),
                $this->previousSecret ?? $this->secret,
            )
            : [];

        // 2. Check Active Blocks (Fail-Fast) on Real Keys
        if ($blocked = $this->checkActiveBlocks($realKeysV2, $realKeysV1, $policy->getName(), $device)) {
            return $blocked;
        }

        // 3. Load budget state for later candidate evaluation. Budget loading is
        // deliberately non-enforcing; normal evaluation must always run first.

        // 4. Observe bounded device caps once, using only opaque package-derived
        // current/previous references. Ephemeral mode is a routing decision;
        // it never creates a synthetic persistent fingerprint key.
        $ephemeralState = null;
        if ($device->fingerprintHash !== null) {
            $ipObservation = $this->buildCorrelationObservation(
                $policy->getName(),
                'device_cap_ip',
                $realKeysV2['k1'] ?? null,
                $device->fingerprintHash,
                $realKeysV1['k1'] ?? null,
                $this->previousFingerprintHash($device),
            );
            $accountObservation = $context->accountId !== null
                ? $this->buildCorrelationObservation(
                    $policy->getName(),
                    'device_cap_account',
                    $this->accountAnchor($realKeysV2),
                    $device->fingerprintHash,
                    $this->accountAnchor($realKeysV1),
                    $this->previousFingerprintHash($device),
                )
                : null;
            $ephemeralState = $this->ephemeralBucket->check($ipObservation, $accountObservation);
        }
        $isEphemeral = $ephemeralState === null ? false : $ephemeralState->isEphemeral;

        $effectiveKeysV2 = $realKeysV2;
        $effectiveKeysV1 = $realKeysV1;

        if ($isEphemeral) {
            unset($effectiveKeysV2['k3'], $effectiveKeysV2['k5']);
            unset($effectiveKeysV1['k3'], $effectiveKeysV1['k5']);
        }

        // IPv6 macro scopes are correlation-only. Resolve their bounded
        // activation once, before the feature-specific observations below.
        $adaptiveIpv6Scopes = $this->resolveAdaptiveIpv6Scopes(
            $policy,
            $context,
            $request,
            $device,
            $realKeysV2,
            $realKeysV1,
        );

        // 5. Fetch & Decay Scores (Using Effective Keys)
        $rawScores = $this->fetchScores($effectiveKeysV2, $effectiveKeysV1);
        $decayedScores = $this->applyDecay($rawScores, $effectiveKeysV2);

        // 6. Evaluate normal candidates. None of these candidates may be
        // hidden by an active account budget.
        $candidates = $this->checkThresholds($policy, $rawScores, $decayedScores, $effectiveKeysV2, $device);

        // 7. Distributed account attack is a pre-check-only observation. It
        // must run before the older bounded correlation rules so a missing
        // snapshot capability cannot leave partial S3-F08 state behind.
        if ($request->isPreCheck && $this->isDistributedAccountPolicy($policy->getName())) {
            if ($candidate = $this->checkDistributedAccountAttack(
                $context,
                $device,
                $policy->getName(),
                $isEphemeral,
                $realKeysV2,
                $realKeysV1,
            )) {
                $candidates[] = $candidate;
            }
        }

        // 8. Check existing bounded correlation rules.
        if ($candidate = $this->checkCorrelationRules(
            $device,
            $policy->getName(),
            $isEphemeral,
            $realKeysV2,
            $realKeysV1,
            $adaptiveIpv6Scopes,
        )) {
            $candidates[] = $candidate;
        }

        // Credential-spray observation is deliberately precheck-only. The
        // later failure/success command in the same host lifecycle must not
        // observe the same spray attempt a second time.
        if ($request->isPreCheck && $this->isCredentialSprayPolicy($policy->getName())) {
            if ($candidate = $this->checkCredentialSpray(
                $context,
                $device,
                $policy->getName(),
                $realKeysV2['k1'] ?? null,
                $realKeysV1['k1'] ?? null,
                $adaptiveIpv6Scopes,
            )) {
                $candidates[] = $candidate;
            }
        }

        // 9. New Device Flood (5.4)
        if ($ephemeralState !== null && $context->accountId && ! $this->isApiHeavyPolicy($policy->getName())
            && $ephemeralState->accountDeviceCount >= 6) {
            $floodKey = $this->auxiliaryAccountKey(
                $policy->getName(),
                'flood_stage',
                $context->accountId,
                $this->secret,
            );
            $previousFloodKey = $this->previousAuxiliaryAccountKey(
                $policy->getName(),
                'flood_stage',
                $context->accountId,
            );
            $isFloodStage = $this->correlationStore->getWatchFlag($floodKey) > 0;
            if (! $isFloodStage && $previousFloodKey !== null && $previousFloodKey !== $floodKey) {
                $isFloodStage = $this->correlationStore->getWatchFlag($previousFloodKey) > 0;
            }

            if ($isFloodStage) {
                $duration = PenaltyLadder::getDuration(2);
                $persistence = [];
                if (! $ephemeralState->isEphemeral && $realKeysV2['k5'] !== null) {
                    $persistence[] = ['key' => $realKeysV2['k5'], 'level' => 2, 'duration' => $duration];
                }

                $candidates[] = $this->candidate(RateLimitResultDTO::DECISION_HARD_BLOCK, 2, $duration, 'flood', $persistence);
            } else {
                $duration = PenaltyLadder::getDuration(1);
                $k4Key = $realKeysV2['k4'];
                $persistence = $k4Key !== null
                    ? [['key' => $k4Key, 'level' => 1, 'duration' => $duration]]
                    : [];
                $this->correlationStore->incrementWatchFlag($floodKey, 900);
                $candidates[] = $this->candidate(RateLimitResultDTO::DECISION_SOFT_BLOCK, 1, $duration, 'flood', $persistence);
            }
        }

        // 10. Process Updates (Failure / Access). Budget counting is part of
        // this step and must continue even while BudgetActive.
        $budgetState = $this->resolveActiveBudgetState($realKeysV2['k4'] ?? null, $realKeysV1['k4'] ?? null);
        $budgetRequestEligible = false;
        $budgetSuppressed = false;
        if (! $request->isPreCheck && ($request->isFailure || $policy->getScoreDeltas()->access > 0)) {
            // We write only to V2 (Active Key); V1 stays read-only
            $updates = $this->processUpdates(
                $policy,
                $context,
                $request,
                $device,
                $isEphemeral,
                $effectiveKeysV2,
                $effectiveKeysV1,
                $rawScores,
            );
            $candidates = array_merge($candidates, $updates['candidates']);
            $budgetState = $updates['budgetState'];
            $budgetRequestEligible = $updates['budgetRequestEligible'];
            $budgetSuppressed = $updates['budgetSuppressed'];
        }

        // Anti-Equilibrium reads prior history before budget cooldown
        // acquisition. Recording happens only after final aggregation.
        if ($request->isFailure && $context->accountId !== null && $policy->getBudgetConfig() !== null
            && $this->antiEquilibriumGate->shouldEscalate(
                $this->auxiliaryAccountKey($policy->getName(), 'anti_equilibrium', $context->accountId, $this->secret),
                $this->previousAuxiliaryAccountKey($policy->getName(), 'anti_equilibrium', $context->accountId),
            )) {
            $persistence = ($realKeysV2['k4'] ?? null) !== null
                ? [['key' => $realKeysV2['k4'], 'level' => 2, 'duration' => PenaltyLadder::getDuration(2)]]
                : [];
            $candidates[] = $this->candidate(
                RateLimitResultDTO::DECISION_HARD_BLOCK,
                2,
                PenaltyLadder::getDuration(2),
                'anti_equilibrium',
                $persistence,
            );
        }

        $final = $this->finalizeDecision(
            $policy,
            $context,
            $request,
            $device,
            $realKeysV2,
            $realKeysV1,
            $candidates,
            $budgetState,
            $budgetRequestEligible,
            $budgetSuppressed,
        );

        if ($final->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK
            && ! $request->isSuccess
            && $context->accountId !== null
            && $policy->getBudgetConfig() !== null) {
            $this->antiEquilibriumGate->recordSoftBlock(
                $this->auxiliaryAccountKey($policy->getName(), 'anti_equilibrium', $context->accountId, $this->secret),
            );
        }

        return $final;
    }

    /**
     * @param   array<string, string|null>  $keysV2
     * @param   array<string, string|null>  $keysV1
     */
    private function checkActiveBlocks(
        array $keysV2,
        array $keysV1,
        string $policyName,
        DeviceIdentityDTO $device,
    ): ?RateLimitResultDTO {
        foreach ([$keysV2, $keysV1] as $keys) {
            foreach ($keys as $keyType => $key) {
                if (! $key) {
                    continue;
                }
                if ($this->isApiHeavyPolicy($policyName) && ! $this->isApiHeavyKeyType($keyType)) {
                    continue;
                }
                if ($this->isTrustedAuthenticationPolicy($policyName, $device) && $this->isK1Key($keyType)) {
                    continue;
                }
                $block = $this->store->checkBlock($key);
                if ($block && $block->level >= 2) {
                    return $this->createBlockedResult($block->level, $block->expiresAt - $this->clock->now()->getTimestamp(), RateLimitResultDTO::DECISION_HARD_BLOCK);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, ?PipelineScoreDTO> $rawScores
     * @param array<string, int> $scores
     * @param array<string, string|null> $keys
     * @return list<array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}>
     */
    private function checkThresholds(
        BlockPolicyInterface $policy,
        array $rawScores,
        array $scores,
        array $keys,
        DeviceIdentityDTO $device,
    ): array {
        $candidates = [];
        foreach ($scores as $keyType => $score) {
            $level = $this->determineLevel($score, $keyType, $policy);

            if ($level > 0) {
                $candidateKeyType = $keyType;
                $candidateLevel = $level;
                if ($this->isApiHeavyPolicy($policy->getName())
                    && $keyType === 'k3'
                    && $device->confidence === 'LOW'
                    && $level >= 2) {
                    $candidateKeyType = 'k2';
                    $candidateLevel = 2;
                }

                $decision = ($candidateLevel >= 2)
                    ? RateLimitResultDTO::DECISION_HARD_BLOCK
                    : RateLimitResultDTO::DECISION_SOFT_BLOCK;
                $source = $this->isTrustedAuthenticationPolicy($policy->getName(), $device) && $this->isK1Key($keyType)
                    ? 'trusted_advisory:score'
                    : 'score';
                $thresholds = $this->getScopedThresholds($keyType, $policy);
                $scoreState = $rawScores[$keyType] ?? null;
                $key = $keys[$keyType] ?? null;
                $retryAfter = PenaltyLadder::getDuration($candidateLevel);
                if ($thresholds !== null && $scoreState !== null && $key !== null) {
                    $exitThreshold = $candidateLevel >= 2 ? $thresholds->l2 : $thresholds->l1;
                    $retryAfter = $this->scoreDecayRetryAfter(
                        $scoreState,
                        $key,
                        $keyType,
                        $exitThreshold,
                    );
                }

                $persistence = [];
                if ($this->isApiHeavyPolicy($policy->getName())
                    && $this->isCanonicalApiHeavyEnforcementKeyType($candidateKeyType)) {
                    $persistenceKey = $keys[$candidateKeyType] ?? null;
                    if ($persistenceKey !== null) {
                        $persistence[] = [
                            'key' => $persistenceKey,
                            'level' => $candidateLevel,
                            'duration' => PenaltyLadder::getDuration($candidateLevel),
                        ];
                    }
                }

                $candidates[] = $this->candidate($decision, $candidateLevel, $retryAfter, $source, $persistence);
            }
        }

        return $candidates;
    }

    /**
     * Observe the distributed-account device window and its account-only
     * repeated-occurrence gate.
     *
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function checkDistributedAccountAttack(
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        string $policyName,
        bool $isEphemeral,
        array $keysV2,
        array $keysV1,
    ): ?array {
        if ($context->accountId === null
            || $device->fingerprintHash === null
            || ($keysV2['k4'] ?? null) === null
            || ($keysV2['k5'] ?? null) === null) {
            return null;
        }

        $devices = $this->buildDistributedDeviceObservation($policyName, $device, $keysV2, $keysV1);
        $snapshot = $this->addBoundedSnapshot($devices, 600, 4);
        $thresholdMet = $snapshot->count >= 4;

        if ($snapshot->count === 3) {
            $watchCount = $this->incrementWatchAcrossRotation(
                $this->correlationStateKey($policyName, 'distributed_account_watch', $devices->currentKey, $this->secret),
                $devices->previousKey === null
                    ? null
                    : $this->correlationStateKey(
                        $policyName,
                        'distributed_account_watch',
                        $devices->previousKey,
                        $this->previousSecret ?? $this->secret,
                    ),
                1800,
            );
            $thresholdMet = $watchCount >= 2;
        }

        if (! $thresholdMet) {
            return null;
        }

        $occurrence = $this->buildDistributedOccurrenceObservation(
            $policyName,
            $snapshot->expiresAt,
            $keysV2,
            $keysV1,
        );
        $occurrenceSnapshot = $this->addBoundedSnapshot($occurrence, 86400, 3);
        $persistence = [];
        $duration = PenaltyLadder::getDuration(2);

        // Persist the complete involved snapshot only for the first qualifying
        // occurrence in this logical device window. Later qualifications must
        // not refresh historical K5 TTLs.
        if ($occurrenceSnapshot->added) {
            foreach ($snapshot->members as $member) {
                $persistence[] = ['key' => $member, 'level' => 2, 'duration' => $duration];
            }
        }

        // A current member may be absent from the bounded distributed set
        // after its cap is reached, or may be represented by a previous
        // generation member during rotation. On the first qualification the
        // complete snapshot already covers represented members; on a later
        // qualification only the current non-ephemeral K5 is refreshed.
        if (! $isEphemeral
            && (! $occurrenceSnapshot->added || ! in_array($keysV2['k5'], $snapshot->members, true))) {
            $persistence[] = ['key' => $keysV2['k5'], 'level' => 2, 'duration' => $duration];
        }

        $level = 2;
        $retryAfter = $duration;
        if ($occurrenceSnapshot->count >= 3) {
            $level = 4;
            $retryAfter = PenaltyLadder::getDuration(4);
            $persistence[] = [
                'key' => $keysV2['k4'],
                'level' => 4,
                'duration' => PenaltyLadder::getDuration(4),
            ];
        }

        return $this->candidate(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            $level,
            $retryAfter,
            'distributed_account_attack',
            $persistence,
        );
    }

    /**
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     */
    private function buildDistributedDeviceObservation(
        string $policyName,
        DeviceIdentityDTO $device,
        array $keysV2,
        array $keysV1,
    ): BoundedCorrelationObservationDTO {
        $currentK4 = $keysV2['k4'] ?? null;
        $currentK5 = $keysV2['k5'] ?? null;
        if ($currentK4 === null || $currentK5 === null) {
            throw new RateLimiterException('Distributed account correlation requires real K4 and K5 keys.');
        }

        $prefix = "{$policyName}:rate_limiter:correlation:distributed_account_devices:v1:{$this->envScope}";
        $currentKey = $this->hashKey("{$prefix}:scope:{$currentK4}", $this->secret);
        // K5 is already the canonical opaque enforcement key. Do not HMAC it
        // again: the snapshot must return a key that can be blocked directly.
        $currentMember = $currentK5;

        if (! $this->hasPreviousGeneration($device)) {
            return new BoundedCorrelationObservationDTO($currentKey, $currentMember);
        }

        $previousK4 = $keysV1['k4'] ?? null;
        $previousK5 = $keysV1['k5'] ?? null;
        if ($previousK4 === null || $previousK5 === null) {
            throw new RateLimiterException('Distributed account rotation requires coordinated previous K4 and K5 keys.');
        }

        $previousSecret = $this->previousSecret ?? $this->secret;
        $previousKey = $this->hashKey("{$prefix}:scope:{$previousK4}", $previousSecret);

        return new BoundedCorrelationObservationDTO(
            $currentKey,
            $currentMember,
            $previousKey,
            $previousK5,
            $this->hashKey("{$prefix}:bridge:{$currentKey}", $this->secret),
        );
    }

    /**
     * Occurrence history is account-only. A fingerprint-only rotation keeps
     * the same occurrence namespace; only an outer-secret rotation has a
     * previous generation.
     *
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     */
    private function buildDistributedOccurrenceObservation(
        string $policyName,
        int $windowExpiresAt,
        array $keysV2,
        array $keysV1,
    ): BoundedCorrelationObservationDTO {
        $currentK4 = $keysV2['k4'] ?? null;
        if ($currentK4 === null) {
            throw new RateLimiterException('Distributed occurrence history requires a real K4 key.');
        }

        $prefix = "{$policyName}:rate_limiter:correlation:distributed_account_occurrences:v1:{$this->envScope}";
        $currentKey = $this->hashKey("{$prefix}:scope:{$currentK4}", $this->secret);
        $currentMember = $this->hashKey("{$prefix}:window:{$windowExpiresAt}", $this->secret);

        if ($this->previousSecret === null) {
            return new BoundedCorrelationObservationDTO($currentKey, $currentMember);
        }

        $previousK4 = $keysV1['k4'] ?? null;
        if ($previousK4 === null) {
            throw new RateLimiterException('Distributed occurrence rotation requires a previous K4 key.');
        }

        $previousKey = $this->hashKey("{$prefix}:scope:{$previousK4}", $this->previousSecret);
        $previousMember = $this->hashKey("{$prefix}:window:{$windowExpiresAt}", $this->previousSecret);

        return new BoundedCorrelationObservationDTO(
            $currentKey,
            $currentMember,
            $previousKey,
            $previousMember,
            $this->hashKey("{$prefix}:bridge:{$currentKey}", $this->secret),
        );
    }

    private function addBoundedSnapshot(
        BoundedCorrelationObservationDTO $observation,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        $now = $this->clock->now()->getTimestamp();
        if ($observation->previousKey === null) {
            if (! $this->correlationStore instanceof BoundedCorrelationSnapshotStoreInterface) {
                throw new RateLimiterException(
                    'Bounded correlation snapshots require the BoundedCorrelationSnapshotStoreInterface capability.',
                );
            }

            $result = $this->correlationStore->addDistinctBoundedWithSnapshot(
                $observation->currentKey,
                $observation->currentMember,
                $ttlSeconds,
                $maxDistinct,
            );

            return BoundedCorrelationResultValidator::snapshot(
                $result,
                $maxDistinct,
                $now,
                $ttlSeconds,
                $observation->currentMember,
            );
        }

        if (! $this->correlationStore instanceof BoundedCorrelationSnapshotRotationStoreInterface) {
            throw new RateLimiterException(
                'Bounded correlation snapshot rotation requires the BoundedCorrelationSnapshotRotationStoreInterface capability.',
            );
        }
        if ($observation->bridgeKey === null || $observation->previousMember === null) {
            throw new RateLimiterException('Malformed bounded correlation snapshot rotation tuple.');
        }

        $result = $this->correlationStore->addDistinctBoundedWithSnapshotAcrossRotation(
            $observation->currentKey,
            $observation->bridgeKey,
            $observation->previousKey,
            $observation->currentMember,
            $observation->previousMember,
            $ttlSeconds,
            $maxDistinct,
        );

        return BoundedCorrelationResultValidator::snapshot(
            $result,
            $maxDistinct,
            $now,
            $ttlSeconds,
            $observation->currentMember,
            $observation->previousMember,
        );
    }

    /**
     * Resolve IPv6 macro detection scopes once for the current request.
     *
     * The activation chain is bounded and deliberately has no near-threshold
     * WATCH state: /48, /40, and /32 are detection namespaces only. A macro
     * scope is returned only after its child threshold is reached.
     *
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     * @return list<array{cidr: int, currentScope: string, previousScope: ?string}>
     */
    private function resolveAdaptiveIpv6Scopes(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
        array $keysV2,
        array $keysV1,
    ): array {
        if (! filter_var($context->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            || ! $this->isAdaptiveIpv6Participant($policy->getName(), $context, $request, $device, $keysV2)) {
            return [];
        }

        $policyName = $policy->getName();
        $prefix48 = $this->getIpPrefix($context->ip, 48);
        $prefix40 = $this->getIpPrefix($context->ip, 40);
        $prefix32 = $this->getIpPrefix($context->ip, 32);
        $hasPreviousOuterGeneration = $this->previousSecret !== null;
        $previousK1 = $hasPreviousOuterGeneration ? ($keysV1['k1'] ?? null) : null;

        $current48 = $this->adaptiveIpv6ScopeKey($policyName, 48, $prefix48, $this->secret);
        $previous48 = $hasPreviousOuterGeneration
            ? $this->adaptiveIpv6ScopeKey($policyName, 48, $prefix48, $this->previousSecret ?? $this->secret)
            : null;
        $scope48Observation = $this->buildAdaptiveHierarchyObservation(
            $policyName,
            48,
            $prefix48,
            $keysV2['k1'] ?? null,
            $previous48,
            $previousK1,
        );
        $scope48Count = $this->addBoundedCorrelation(
            $scope48Observation,
            self::IPV6_ADAPTIVE_WINDOW_SECONDS,
            self::IPV6_ADAPTIVE_48_CAP,
        );
        if ($scope48Count < self::IPV6_ADAPTIVE_48_CAP) {
            return [];
        }

        $activeScopes = [[
            'cidr' => 48,
            'currentScope' => $current48,
            'previousScope' => $previous48,
        ]];

        $current40 = $this->adaptiveIpv6ScopeKey($policyName, 40, $prefix40, $this->secret);
        $previous40 = $hasPreviousOuterGeneration
            ? $this->adaptiveIpv6ScopeKey($policyName, 40, $prefix40, $this->previousSecret ?? $this->secret)
            : null;
        $scope40Observation = $this->buildAdaptiveHierarchyObservation(
            $policyName,
            40,
            $prefix40,
            $current48,
            $previous40,
            $previous48,
        );
        $scope40Count = $this->addBoundedCorrelation(
            $scope40Observation,
            self::IPV6_ADAPTIVE_WINDOW_SECONDS,
            self::IPV6_ADAPTIVE_40_CAP,
        );
        if ($scope40Count < self::IPV6_ADAPTIVE_40_CAP) {
            return $activeScopes;
        }

        $activeScopes[] = [
            'cidr' => 40,
            'currentScope' => $current40,
            'previousScope' => $previous40,
        ];

        $current32 = $this->adaptiveIpv6ScopeKey($policyName, 32, $prefix32, $this->secret);
        $previous32 = $hasPreviousOuterGeneration
            ? $this->adaptiveIpv6ScopeKey($policyName, 32, $prefix32, $this->previousSecret ?? $this->secret)
            : null;
        $scope32Observation = $this->buildAdaptiveHierarchyObservation(
            $policyName,
            32,
            $prefix32,
            $current40,
            $previous32,
            $previous40,
        );
        $scope32Count = $this->addBoundedCorrelation(
            $scope32Observation,
            self::IPV6_ADAPTIVE_WINDOW_SECONDS,
            self::IPV6_ADAPTIVE_32_CAP,
        );
        if ($scope32Count >= self::IPV6_ADAPTIVE_32_CAP) {
            $activeScopes[] = [
                'cidr' => 32,
                'currentScope' => $current32,
                'previousScope' => $previous32,
            ];
        }

        return $activeScopes;
    }

    /**
     * A request participates in hierarchy state only through an eligible
     * pre-check spray observation or the existing bounded fingerprint path.
     *
     * @param array<string, string|null> $keysV2
     */
    private function isAdaptiveIpv6Participant(
        string $policyName,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
        array $keysV2,
    ): bool {
        $spraySubject = $context->correlationId ?? $context->accountId;
        $sprayEligible = $request->isPreCheck
            && $this->isCredentialSprayPolicy($policyName)
            && $spraySubject !== null
            && ($keysV2['k1'] ?? null) !== null;

        return $sprayEligible || $device->fingerprintHash !== null;
    }

    private function adaptiveIpv6ScopeKey(string $policyName, int $cidr, string $prefix, string $secret): string
    {
        return $this->hashKey(
            "{$policyName}:rate_limiter:ipv6_adaptive:hierarchy:v1:{$cidr}:{$this->envScope}:{$prefix}",
            $secret,
        );
    }

    private function buildAdaptiveHierarchyObservation(
        string $policyName,
        int $cidr,
        string $currentPrefix,
        ?string $currentMember,
        ?string $previousScope,
        ?string $previousMember,
    ): BoundedCorrelationObservationDTO {
        $currentScope = $this->adaptiveIpv6ScopeKey($policyName, $cidr, $currentPrefix, $this->secret);
        if ($currentMember === null) {
            throw new RateLimiterException('IPv6 adaptive hierarchy requires a canonical K1 member.');
        }

        if ($previousScope === null) {
            return new BoundedCorrelationObservationDTO($currentScope, $currentMember);
        }
        if ($this->previousSecret === null || $previousMember === null) {
            throw new RateLimiterException(
                'IPv6 adaptive hierarchy rotation requires coordinated previous outer-key state.',
            );
        }

        return new BoundedCorrelationObservationDTO(
            $currentScope,
            $currentMember,
            $previousScope,
            $previousMember,
            $this->hashKey(
                "{$policyName}:rate_limiter:ipv6_adaptive:hierarchy:v1:{$cidr}:{$this->envScope}:bridge:{$currentScope}",
                $this->secret,
            ),
        );
    }

    private function buildAdaptiveCorrelationObservation(
        string $policyName,
        string $purpose,
        int $cidr,
        string $currentAnchor,
        string $currentMemberSeed,
        ?string $previousAnchor,
        ?string $previousMemberSeed,
    ): BoundedCorrelationObservationDTO {
        $namespace = "{$policyName}:rate_limiter:ipv6_adaptive:{$purpose}:v1:{$cidr}:{$this->envScope}";
        $currentKey = $this->hashKey("{$namespace}:scope:{$currentAnchor}", $this->secret);
        $currentMember = $this->hashKey("{$namespace}:member:{$currentMemberSeed}", $this->secret);
        $hasPrevious = $previousAnchor !== null
            && ($this->previousSecret !== null || $previousMemberSeed !== null);
        if (! $hasPrevious) {
            return new BoundedCorrelationObservationDTO($currentKey, $currentMember);
        }

        $previousSecret = $this->previousSecret ?? $this->secret;
        $previousMemberSeed ??= $currentMemberSeed;
        $previousKey = $this->hashKey("{$namespace}:scope:{$previousAnchor}", $previousSecret);
        $previousMember = $this->hashKey("{$namespace}:member:{$previousMemberSeed}", $previousSecret);

        return new BoundedCorrelationObservationDTO(
            $currentKey,
            $currentMember,
            $previousKey,
            $previousMember,
            $this->hashKey("{$namespace}:bridge:{$currentKey}", $this->secret),
        );
    }

    /**
     * @param array{cidr: int, currentScope: string, previousScope: ?string} $scope
     */
    private function buildAdaptiveChurnObservation(
        string $policyName,
        DeviceIdentityDTO $device,
        array $scope,
    ): BoundedCorrelationObservationDTO {
        if ($device->fingerprintHash === null) {
            throw new RateLimiterException('IPv6 adaptive churn requires a current fingerprint hash.');
        }

        $currentAnchor = $this->adaptiveChurnAnchor(
            $policyName,
            $scope['cidr'],
            $scope['currentScope'],
            $device->normalizedUa,
            $this->secret,
        );
        $previousAnchor = null;
        $previousMemberSeed = null;
        if ($scope['previousScope'] !== null) {
            $previousAnchor = $this->adaptiveChurnAnchor(
                $policyName,
                $scope['cidr'],
                $scope['previousScope'],
                $device->normalizedUa,
                $this->previousSecret ?? $this->secret,
            );
            $previousMemberSeed = $this->previousFingerprintHash($device);
        } elseif ($device->previousFingerprintHash !== null) {
            // Fingerprint-only rotation changes members, not the hierarchy
            // scope. It must not create a previous hierarchy generation.
            $previousAnchor = $currentAnchor;
            $previousMemberSeed = $device->previousFingerprintHash;
        }

        return $this->buildAdaptiveCorrelationObservation(
            $policyName,
            'churn',
            $scope['cidr'],
            $currentAnchor,
            $device->fingerprintHash,
            $previousAnchor,
            $previousMemberSeed,
        );
    }

    private function adaptiveChurnAnchor(
        string $policyName,
        int $cidr,
        string $adaptiveScope,
        string $normalizedUa,
        string $secret,
    ): string {
        return $this->hashKey(
            "{$policyName}:rate_limiter:ipv6_adaptive:churn_anchor:v1:{$cidr}:{$this->envScope}:{$adaptiveScope}:{$normalizedUa}",
            $secret,
        );
    }

    /**
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     * @param list<array{cidr: int, currentScope: string, previousScope: ?string}> $adaptiveIpv6Scopes
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function checkCorrelationRules(
        DeviceIdentityDTO $device,
        string $policyName,
        bool $isEphemeral,
        array $keysV2,
        array $keysV1,
        array $adaptiveIpv6Scopes,
    ): ?array {
        if ($device->fingerprintHash === null) {
            return null;
        }

        $churn = $this->buildCorrelationObservation(
            $policyName,
            'churn',
            $keysV2['k2'] ?? null,
            $device->fingerprintHash,
            $keysV1['k2'] ?? null,
            $this->previousFingerprintHash($device),
        );
        $churnTriggered = $this->observeChurn(
            $churn,
            $this->correlationStateKey($policyName, 'churn_watch', $churn->currentKey, $this->secret),
            $churn->previousKey === null
                ? null
                : $this->correlationStateKey(
                    $policyName,
                    'churn_watch',
                    $churn->previousKey,
                    $this->previousSecret ?? $this->secret,
                ),
        );

        // Every active macro churn scope is observed before selecting a
        // candidate. Macro scopes never become enforcement keys.
        foreach ($adaptiveIpv6Scopes as $scope) {
            $macroChurn = $this->buildAdaptiveChurnObservation($policyName, $device, $scope);
            $macroChurnTriggered = $this->observeChurn(
                $macroChurn,
                $macroChurn->currentKey . ':watch',
                $macroChurn->previousKey === null ? null : $macroChurn->previousKey . ':watch',
            );
            $churnTriggered = $churnTriggered || $macroChurnTriggered;
        }

        if ($churnTriggered) {
            return $this->candidate(
                RateLimitResultDTO::DECISION_HARD_BLOCK,
                2,
                60,
                'correlation',
                isset($keysV2['k2'])
                    ? [['key' => $keysV2['k2'], 'level' => 2, 'duration' => 60]]
                    : [],
            );
        }

        // Ephemeral overflow has no durable per-fingerprint dilution state.
        // Churn above remains available because it is a bounded K2 signal.
        if ($isEphemeral) {
            return null;
        }

        $dilution = $this->buildCorrelationObservation(
            $policyName,
            'dilution',
            $device->fingerprintHash,
            $keysV2['k1'] ?? null,
            $this->previousFingerprintHash($device),
            $keysV1['k1'] ?? null,
        );
        if ($dilution->currentMember === '') {
            return null;
        }

        $dilutionCount = $this->addBoundedCorrelation($dilution, 600, 6);
        $thresholdMet = $dilutionCount >= 6;
        if ($dilutionCount === 5) {
            $watchCount = $this->incrementWatchAcrossRotation(
                $this->correlationStateKey($policyName, 'dilution_watch', $dilution->currentKey, $this->secret),
                $dilution->previousKey === null
                    ? null
                    : $this->correlationStateKey(
                        $policyName,
                        'dilution_watch',
                        $dilution->previousKey,
                        $this->previousSecret ?? $this->secret,
                    ),
                1800,
            );
            $thresholdMet = $watchCount >= 2;
        }

        if (! $thresholdMet) {
            return null;
        }

        if ($device->confidence === 'LOW') {
            return $this->candidate(
                RateLimitResultDTO::DECISION_HARD_BLOCK,
                2,
                60,
                'correlation',
                isset($keysV2['k2'])
                    ? [['key' => $keysV2['k2'], 'level' => 2, 'duration' => 60]]
                    : [],
            );
        }

        $windowId = (int) floor($this->clock->now()->getTimestamp() / 600);
        $this->correlationStore->incrementWatchFlag(
            $this->correlationStateKey($policyName, 'dilution_confirmation', $dilution->currentKey . ':' . $windowId, $this->secret),
            1200,
        );
        $previousWindowId = $windowId - 1;
        $previousWindowCount = $this->correlationStore->getWatchFlag(
            $this->correlationStateKey(
                $policyName,
                'dilution_confirmation',
                $dilution->currentKey . ':' . $previousWindowId,
                $this->secret,
            ),
        );
        if ($dilution->previousKey !== null) {
            $previousWindowCount = max(
                $previousWindowCount,
                $this->correlationStore->getWatchFlag(
                    $this->correlationStateKey(
                        $policyName,
                        'dilution_confirmation',
                        $dilution->previousKey . ':' . $previousWindowId,
                        $this->previousSecret ?? $this->secret,
                    ),
                ),
            );
        }

        if ($previousWindowCount === 0 || $keysV2['k3'] === null) {
            return null;
        }

        return $this->candidate(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            2,
            60,
            'correlation',
            [['key' => $keysV2['k3'], 'level' => 2, 'duration' => 60]],
        );
    }

    private function observeChurn(
        BoundedCorrelationObservationDTO $observation,
        string $currentWatchKey,
        ?string $previousWatchKey,
    ): bool {
        $count = $this->addBoundedCorrelation($observation, 600, 3);
        if ($count >= 3) {
            return true;
        }

        if ($count !== 2) {
            return false;
        }

        return $this->incrementWatchAcrossRotation($currentWatchKey, $previousWatchKey, 1800) >= 2;
    }

    private function addBoundedCorrelation(
        BoundedCorrelationObservationDTO $observation,
        int $ttlSeconds,
        int $maxDistinct,
    ): int {
        if ($observation->previousKey === null) {
            if (! $this->correlationStore instanceof BoundedCorrelationStoreInterface) {
                throw new RateLimiterException(
                    'Bounded correlation requires the BoundedCorrelationStoreInterface capability.',
                );
            }

            $result = $this->correlationStore->addDistinctBounded(
                $observation->currentKey,
                $observation->currentMember,
                $ttlSeconds,
                $maxDistinct,
            );

            return BoundedCorrelationResultValidator::count($result, $maxDistinct);
        }

        if (! $this->correlationStore instanceof BoundedCorrelationRotationStoreInterface) {
            throw new RateLimiterException(
                'Bounded correlation rotation requires the BoundedCorrelationRotationStoreInterface capability.',
            );
        }
        if ($observation->bridgeKey === null || $observation->previousMember === null) {
            throw new RateLimiterException('Malformed bounded correlation observation rotation tuple.');
        }

        $result = $this->correlationStore->addDistinctBoundedAcrossRotation(
            $observation->currentKey,
            $observation->bridgeKey,
            $observation->previousKey,
            $observation->currentMember,
            $observation->previousMember,
            $ttlSeconds,
            $maxDistinct,
        );

        return BoundedCorrelationResultValidator::count($result, $maxDistinct);
    }

    private function incrementWatchAcrossRotation(
        string $currentKey,
        ?string $previousKey,
        int $ttlSeconds,
    ): int {
        if ($previousKey === null || $previousKey === $currentKey) {
            return $this->correlationStore->incrementWatchFlag($currentKey, $ttlSeconds);
        }
        if (! $this->correlationStore instanceof CorrelationRotationStoreInterface) {
            throw new RateLimiterException(
                'Correlation WATCH rotation requires the CorrelationRotationStoreInterface capability.',
            );
        }

        return $this->correlationStore->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    private function buildCorrelationObservation(
        string $policyName,
        string $purpose,
        ?string $currentAnchor,
        ?string $currentMemberSeed,
        ?string $previousAnchor,
        ?string $previousMemberSeed,
    ): BoundedCorrelationObservationDTO {
        if ($currentAnchor === null || $currentMemberSeed === null) {
            throw new RateLimiterException("Missing current opaque anchor for {$purpose} correlation state.");
        }

        $prefix = "{$policyName}:rate_limiter:correlation:{$purpose}:v1:{$this->envScope}";
        $currentKey = $this->hashKey("{$prefix}:scope:{$currentAnchor}", $this->secret);
        $currentMember = $this->hashKey("{$prefix}:member:{$currentMemberSeed}", $this->secret);

        $hasPrevious = $previousAnchor !== null
            && ($this->previousSecret !== null || $previousMemberSeed !== null);
        if (! $hasPrevious) {
            return new BoundedCorrelationObservationDTO($currentKey, $currentMember);
        }

        $previousSecret = $this->previousSecret ?? $this->secret;
        $previousMemberSeed ??= $currentMemberSeed;
        $previousKey = $this->hashKey("{$prefix}:scope:{$previousAnchor}", $previousSecret);
        $previousMember = $this->hashKey("{$prefix}:member:{$previousMemberSeed}", $previousSecret);
        $bridgeKey = $this->hashKey("{$prefix}:bridge:{$currentKey}", $this->secret);

        return new BoundedCorrelationObservationDTO(
            $currentKey,
            $currentMember,
            $previousKey,
            $previousMember,
            $bridgeKey,
        );
    }

    /**
     * @param array<string, string|null> $keys
     */
    private function accountAnchor(array $keys): ?string
    {
        if (($keys['k4'] ?? null) === null || ($keys['k1'] ?? null) === null) {
            return null;
        }

        return $keys['k4'] . ':' . $keys['k1'];
    }

    private function correlationStateKey(string $policyName, string $purpose, string $anchor, string $secret): string
    {
        return $this->hashKey(
            "{$policyName}:rate_limiter:correlation:{$purpose}:v1:{$this->envScope}:{$anchor}",
            $secret,
        );
    }

    /**
     * Observe credential spray subjects for authentication pre-checks only.
     *
     * The subject member is always a domain-separated HMAC. Raw account or
     * correlation identities never cross the correlation-store boundary.
     *
     * @param list<array{cidr: int, currentScope: string, previousScope: ?string}> $adaptiveIpv6Scopes
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function checkCredentialSpray(
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        string $policyName,
        ?string $k1Key,
        ?string $previousK1Key,
        array $adaptiveIpv6Scopes,
    ): ?array {
        $subject = $context->correlationId ?? $context->accountId;
        if ($subject === null || $k1Key === null) {
            return null;
        }

        $thresholdMet = $this->observeCredentialSpray(
            $this->buildCredentialSprayObservation($subject, $k1Key, $previousK1Key),
        );

        // Observe every active macro scope before choosing the final spray
        // candidate. The current canonical K1 remains the only persistence key.
        foreach ($adaptiveIpv6Scopes as $scope) {
            $macroObservation = $this->buildAdaptiveCorrelationObservation(
                $policyName,
                'spray',
                $scope['cidr'],
                $scope['currentScope'],
                $subject,
                $scope['previousScope'],
                $subject,
            );
            $thresholdMet = $this->observeCredentialSpray($macroObservation) || $thresholdMet;
        }

        if (! $thresholdMet) {
            return null;
        }

        $source = $this->isTrustedAuthenticationPolicy($policyName, $device)
            ? 'trusted_advisory:credential_spray'
            : 'credential_spray';

        return $this->candidate(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            2,
            PenaltyLadder::getDuration(2),
            $source,
            [['key' => $k1Key, 'level' => 2, 'duration' => PenaltyLadder::getDuration(2)]],
        );
    }

    private function observeCredentialSpray(BoundedCorrelationObservationDTO $observation): bool
    {
        $count = $this->addBoundedCorrelation($observation, 600, 5);
        if ($count >= 5) {
            return true;
        }

        if ($count !== 4) {
            return false;
        }

        $watchCount = $this->incrementWatchAcrossRotation(
            $observation->currentKey . ':watch',
            $observation->previousKey === null ? null : $observation->previousKey . ':watch',
            1800,
        );

        return $watchCount >= 2;
    }

    private function buildCredentialSprayObservation(
        string $subject,
        string $k1Key,
        ?string $previousK1Key,
    ): BoundedCorrelationObservationDTO {
        $currentKey = 'credential_spray:' . $k1Key;
        $currentMember = $this->hashKey('credential_spray:subject:v1:' . $subject, $this->secret);
        if ($this->previousSecret === null) {
            return new BoundedCorrelationObservationDTO($currentKey, $currentMember);
        }

        if ($previousK1Key === null) {
            throw new RateLimiterException('Credential-spray key rotation requires a previous K1 correlation key.');
        }

        return new BoundedCorrelationObservationDTO(
            $currentKey,
            $currentMember,
            'credential_spray:' . $previousK1Key,
            $this->hashKey('credential_spray:subject:v1:' . $subject, $this->previousSecret),
            'credential_spray:bridge:' . $k1Key,
        );
    }

    /**
     * @param array<string, string|null> $keys
     * @param array<string, string|null> $keysV1
     * @param array<string, ?PipelineScoreDTO> $rawScores
     * @return array{
     *     candidates: list<array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}>,
     *     budgetState: ?BudgetStateDTO,
     *     budgetRequestEligible: bool,
     *     budgetSuppressed: bool
     * }
     */
    private function processUpdates(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
        bool $isEphemeral,
        array $keys,
        array $keysV1,
        array $rawScores,
    ): array {
        $deltas = $this->calculateDeltas($policy, $context, $device, $request, $isEphemeral);

        $k4Repeated = $policy->getScoreDeltas()->k4_repeated_missing_fp;
        if ($request->isFailure && empty($device->fingerprintHash) && $context->accountId && $k4Repeated > 0) {
            $key = $this->auxiliaryAccountKey(
                $policy->getName(),
                'last_missing_fp',
                $context->accountId,
                $this->secret,
            );
            $last = $this->store->get($key);
            if ($last === null) {
                $previousKey = $this->previousAuxiliaryAccountKey(
                    $policy->getName(),
                    'last_missing_fp',
                    $context->accountId,
                );
                if ($previousKey !== null && $previousKey !== $key) {
                    $last = $this->store->get($previousKey);
                }
            }
            if ($last && ($this->clock->now()->getTimestamp() - $last->value) <= 1800) {
                $deltas['k4'] += $k4Repeated;
            }
            $this->store->set($key, $this->clock->now()->getTimestamp(), 3600);
        }

        $newMaxLevel = 0;
        /** @var array<string, int> $levelsByKeyType */
        $levelsByKeyType = [];
        /** @var array<string, int> $retryAfterByKeyType */
        $retryAfterByKeyType = [];

        foreach ($keys as $keyType => $key) {
            if (! $key) {
                continue;
            }

            $delta = $deltas[$keyType] ?? 0;
            if ($delta > 0) {
                $scoreDto = $rawScores[$keyType] ?? null;
                $rawVal = $scoreDto ? $scoreDto->value : 0;
                $updatedAt = $scoreDto ? $scoreDto->updatedAt : $this->clock->now()->getTimestamp();

                $decayed = $this->calculateDecayedScore($rawVal, $updatedAt, $keyType, $key);
                $baseValue = ($scoreDto && ! $scoreDto->isFromV1) ? $rawVal : 0;
                $netChange = ($decayed + $delta) - $baseValue;

                $newScore = $this->store->increment($key, 86400, (int) $netChange);
                $actualScoreLevel = $this->determineLevel($newScore, $keyType, $policy);
                $level = $actualScoreLevel;

                $watchEscalated = false;

                $thresholdsDto = $this->getScopedThresholds($keyType, $policy);
                if ($thresholdsDto) {
                    // Check each represented threshold once. Equal threshold
                    // values imply the highest represented level.
                    foreach ($this->normalizedWatchThresholds($thresholdsDto) as $thresh => $impliedLevel) {
                        if ($newScore == $thresh - 1) {
                            $wKey = "watch:{$key}";
                            $flags = $this->correlationStore->incrementWatchFlag($wKey, 1800);
                            // If watched twice, upgrade level effectively
                            if ($flags >= 2) {
                                if ($impliedLevel > $level) {
                                    $watchEscalated = true;
                                    $level = $impliedLevel;
                                }
                            }
                        }
                    }
                }

                $newMaxLevel = max($newMaxLevel, $level);
                if ($level <= 0) {
                    continue;
                }

                $levelsByKeyType[$keyType] = $level;
                $retryAfterByKeyType[$keyType] = PenaltyLadder::getDuration($level);
                if (! $watchEscalated && $actualScoreLevel > 0 && $thresholdsDto !== null) {
                    $exitThreshold = $level >= 2 ? $thresholdsDto->l2 : $thresholdsDto->l1;
                    $retryAfterByKeyType[$keyType] = $this->decayCalculator->secondsUntilBelowThreshold(
                        $newScore,
                        $this->clock->now()->getTimestamp(),
                        $this->currentBlockLevel($key),
                        $this->scopeForKeyType($keyType),
                        $exitThreshold,
                    );
                }
            }
        }

        $budgetConfig = $policy->getBudgetConfig();
        $budgetState = $budgetConfig !== null
            ? $this->resolveActiveBudgetState($keys['k4'] ?? null, $keysV1['k4'] ?? null)
            : null;
        $budgetRequestEligible = false;
        $budgetSuppressed = false;

        if (($keys['k4'] ?? null) !== null && $budgetConfig !== null && $request->isFailure) {
            $config = $budgetConfig;
            $shouldCount = false;

            // New/unverified-device and repeated-missing-fingerprint failures
            // contribute directly to the account budget.
            if ($deltas['k4'] > 0 || empty($device->fingerprintHash)) {
                $shouldCount = true;
            }

            // A policy-owned micro-cap gates known-device failures when
            // configured. A null cap means known-device failures are directly
            // budget-eligible; the engine does not identify policy presets.
            if (! $isEphemeral && $deltas['k5'] > 0 && $context->accountId && $device->fingerprintHash
                && $this->isKnownForAccount($device)) {
                if ($config->known_device_micro_cap === null) {
                    $shouldCount = true;
                } else {
                    $microRawV2 = "{$policy->getName()}:rate_limiter:microcap:k5:v1:{$context->accountId}:{$device->fingerprintHash}";
                    $microKeyV2 = $this->hashKey($microRawV2, $this->secret);
                    $microKeyV1 = null;

                    if ($this->hasPreviousGeneration($device)) {
                        $microRawV1 = "{$policy->getName()}:rate_limiter:microcap:k5:v1:{$context->accountId}:{$this->previousFingerprintHash($device)}";
                        $microKeyV1 = $this->hashKey($microRawV1, $this->previousSecret ?? $this->secret);
                    }

                    $microState = $this->incrementBudgetAcrossRotation($microKeyV2, $microKeyV1);
                    if ($microState->count > $config->known_device_micro_cap) {
                        $shouldCount = true;
                    }
                }
            }

            $budgetRequestEligible = $shouldCount;

            if ($shouldCount) {
                $previousBudgetState = $budgetState;
                $budgetState = $this->incrementBudgetAcrossRotation($keys['k4'], $keysV1['k4'] ?? null);
                if ($config->recovery_collision_guard_enabled
                    && $previousBudgetState !== null
                    && $previousBudgetState->count === $config->threshold - 1
                    && $budgetState->count === $config->threshold
                    && ($device->confidence === 'HIGH' || $this->isKnownForAccount($device))) {
                    $budgetSuppressed = true;
                    $budgetRequestEligible = false;
                    // Recovery Guard is a one-shot normal soft candidate. It
                    // deliberately does not acquire budget cooldown.
                    $recoveryCandidate = $this->candidate(
                        RateLimitResultDTO::DECISION_SOFT_BLOCK,
                        2,
                        PenaltyLadder::getDuration(2),
                        'recovery_guard',
                    );
                } else {
                    $recoveryCandidate = null;
                }
            } else {
                $recoveryCandidate = null;
            }
        } else {
            $recoveryCandidate = null;
        }

        $candidates = [];
        if ($newMaxLevel > 0) {
            if ($this->isApiHeavyPolicy($policy->getName())) {
                foreach ($levelsByKeyType as $keyType => $level) {
                    if ($level <= 0 || ! $this->isApiHeavyKeyType($keyType)) {
                        continue;
                    }

                    $candidateKeyType = $keyType;
                    $candidateLevel = $level;
                    if ($keyType === 'k3' && $device->confidence === 'LOW' && $level >= 2) {
                        $candidateKeyType = 'k2';
                        $candidateLevel = 2;
                    }

                    $decision = $candidateLevel >= 2
                        ? RateLimitResultDTO::DECISION_HARD_BLOCK
                        : RateLimitResultDTO::DECISION_SOFT_BLOCK;
                    $duration = PenaltyLadder::getDuration($candidateLevel);
                    $persistenceKey = $this->isCanonicalApiHeavyEnforcementKeyType($candidateKeyType)
                        ? $keys[$candidateKeyType] ?? null
                        : null;
                    $persistence = $persistenceKey === null
                        ? []
                        : [['key' => $persistenceKey, 'level' => $candidateLevel, 'duration' => $duration]];

                    $candidates[] = $this->candidate(
                        $decision,
                        $candidateLevel,
                        $retryAfterByKeyType[$keyType] ?? $duration,
                        $keyType === 'k3' && $candidateKeyType === 'k2'
                            ? 'score_update:low_confidence_k3_to_k2'
                            : 'score_update',
                        $persistence,
                    );
                }
            } elseif ($this->isTrustedAuthenticationPolicy($policy->getName(), $device)) {
                foreach ($levelsByKeyType as $keyType => $level) {
                    if ($level <= 0) {
                        continue;
                    }
                    $decision = ($level >= 2) ? RateLimitResultDTO::DECISION_HARD_BLOCK : RateLimitResultDTO::DECISION_SOFT_BLOCK;
                    $duration = PenaltyLadder::getDuration($level);
                    $retryAfter = $retryAfterByKeyType[$keyType] ?? $duration;
                    $isAdvisory = $this->isK1Key($keyType);
                    $persistence = [];
                    if (! $isAdvisory && $context->accountId && ($keys['k4'] ?? null) !== null) {
                        $persistence[] = ['key' => $keys['k4'], 'level' => $level, 'duration' => $duration];
                    }
                    $candidates[] = $this->candidate(
                        $decision,
                        $level,
                        $retryAfter,
                        $isAdvisory ? 'trusted_advisory:score_update' : 'score_update',
                        $persistence,
                    );
                }
            } else {
                $decision = ($newMaxLevel >= 2) ? RateLimitResultDTO::DECISION_HARD_BLOCK : RateLimitResultDTO::DECISION_SOFT_BLOCK;
                $duration = PenaltyLadder::getDuration($newMaxLevel);
                $retryAfter = 0;
                foreach ($levelsByKeyType as $keyType => $level) {
                    $keyDecision = $level >= 2
                        ? RateLimitResultDTO::DECISION_HARD_BLOCK
                        : RateLimitResultDTO::DECISION_SOFT_BLOCK;
                    if ($keyDecision === $decision) {
                        $retryAfter = max($retryAfter, $retryAfterByKeyType[$keyType] ?? $duration);
                    }
                }
                if ($retryAfter === 0) {
                    $retryAfter = $duration;
                }
                $persistence = [];

                if ($context->accountId && ($keys['k4'] ?? null) !== null) {
                    $persistence[] = ['key' => $keys['k4'], 'level' => $newMaxLevel, 'duration' => $duration];
                }

                $candidates[] = $this->candidate($decision, $newMaxLevel, $retryAfter, 'score_update', $persistence);
            }
        }

        if ($recoveryCandidate !== null) {
            $candidates[] = $recoveryCandidate;
        }

        return [
            'candidates' => $candidates,
            'budgetState' => $budgetState,
            'budgetRequestEligible' => $budgetRequestEligible,
            'budgetSuppressed' => $budgetSuppressed,
        ];
    }

    /**
     * @param list<array{key: string, level: int, duration: int}> $persistence
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}
     */
    private function candidate(string $decision, int $level, int $retryAfter, string $source, array $persistence = []): array
    {
        return [
            'decision' => $decision,
            'level' => $level,
            'retryAfter' => max(0, $retryAfter),
            'source' => $source,
            'persistence' => $persistence,
        ];
    }

    /**
     * @param list<array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}> $candidates
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function aggregateCandidates(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $winningClass = RateLimitResultDTO::DECISION_ALLOW;
        foreach ($candidates as $candidate) {
            if ($candidate['decision'] === RateLimitResultDTO::DECISION_HARD_BLOCK) {
                $winningClass = RateLimitResultDTO::DECISION_HARD_BLOCK;
                break;
            }
            if ($candidate['decision'] === RateLimitResultDTO::DECISION_SOFT_BLOCK) {
                $winningClass = RateLimitResultDTO::DECISION_SOFT_BLOCK;
            }
        }

        if ($winningClass === RateLimitResultDTO::DECISION_ALLOW) {
            return null;
        }

        $classCandidates = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => $candidate['decision'] === $winningClass,
        ));

        $highestLevel = 0;
        $longestRetryAfter = 0;
        foreach ($classCandidates as $candidate) {
            $highestLevel = max($highestLevel, $candidate['level']);
            $longestRetryAfter = max($longestRetryAfter, $candidate['retryAfter']);
        }

        return $this->candidate($winningClass, $highestLevel, $longestRetryAfter, 'aggregate');
    }

    /**
     * @param list<array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}> $candidates
     */
    private function persistWinningCandidates(array $candidates, ?string $winningClass, bool $persistAllCandidates = false): void
    {
        /** @var array<string, array{level: int, duration: int}> $blocks */
        $blocks = [];
        foreach ($candidates as $candidate) {
            if (! $persistAllCandidates && $winningClass !== null && $candidate['decision'] !== $winningClass) {
                continue;
            }

            foreach ($candidate['persistence'] as $block) {
                $current = $blocks[$block['key']] ?? ['level' => 0, 'duration' => 0];
                $blocks[$block['key']] = [
                    'level' => max($current['level'], $block['level']),
                    'duration' => max($current['duration'], $block['duration']),
                ];
            }
        }

        foreach ($blocks as $key => $block) {
            $this->store->block($key, $block['level'], $block['duration']);
        }
    }

    /**
     * @param array<string, string|null> $keysV2
     * @param array<string, string|null> $keysV1
     * @param list<array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}> $candidates
     */
    private function finalizeDecision(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
        array $keysV2,
        array $keysV1,
        array $candidates,
        ?BudgetStateDTO $budgetState,
        bool $budgetRequestEligible,
        bool $budgetSuppressed,
    ): RateLimitResultDTO {
        $advisoryCandidates = array_values(array_filter(
            $candidates,
            fn(array $candidate): bool => str_starts_with($candidate['source'], 'trusted_advisory:'),
        ));
        $candidates = array_values(array_filter(
            $candidates,
            fn(array $candidate): bool => ! str_starts_with($candidate['source'], 'trusted_advisory:'),
        ));

        $normalCandidate = $this->aggregateCandidates($candidates);
        $config = $policy->getBudgetConfig();
        $budgetKey = $this->resolveActiveBudgetKeyV2ThenV1($keysV2['k4'] ?? null, $keysV1['k4'] ?? null);
        $budgetActive = $config !== null
            && $budgetState !== null
            && $budgetKey !== null
            && $this->budgetTracker->isExceeded($budgetKey, $config->threshold);

        if ($budgetActive
            && ! $budgetSuppressed
            && $this->isBudgetCommandEligible($config, $request, $budgetRequestEligible)
            && ($normalCandidate === null || $normalCandidate['decision'] !== RateLimitResultDTO::DECISION_HARD_BLOCK)
            && ($request->isFailure || $normalCandidate === null || $normalCandidate['decision'] === RateLimitResultDTO::DECISION_ALLOW)) {
            $cooldown = $this->acquireBudgetCooldown(
                $policy,
                $context->accountId,
                $device,
                $config->cooldown_seconds,
            );

            if ($cooldown['issued']) {
                $level = $config->block_level;
                if ($device->isTrustedSession) {
                    $level = max($config->trusted_session_floor_level, $level - 1);
                }
                $candidates[] = $this->candidate(
                    RateLimitResultDTO::DECISION_SOFT_BLOCK,
                    $level,
                    $cooldown['retryAfter'],
                    'budget',
                );
            }
        }

        $final = $this->aggregateCandidates($candidates);
        if ($final === null) {
            $this->persistWinningCandidates($advisoryCandidates, null);

            return $this->createAllowResult();
        }

        $this->persistWinningCandidates(
            $candidates,
            $final['decision'],
            $this->isApiHeavyPolicy($policy->getName()),
        );
        $this->persistWinningCandidates($advisoryCandidates, null);

        return $this->createBlockedResult($final['level'], $final['retryAfter'], $final['decision']);
    }

    private function isBudgetCommandEligible(
        \Maatify\RateLimiter\DTO\BudgetConfigDTO $config,
        RateLimitCommand $request,
        bool $failureEligible,
    ): bool {
        if ($request->isSuccess) {
            return false;
        }

        if ($request->isPreCheck) {
            return $config->precheck_enforcement;
        }

        return $request->isFailure && $failureEligible;
    }

    /**
     * @return array{issued: bool, retryAfter: int}
     */
    private function acquireBudgetCooldown(
        BlockPolicyInterface $policy,
        ?string $accountId,
        DeviceIdentityDTO $device,
        int $cooldownSeconds,
    ): array {
        if ($accountId === null) {
            return ['issued' => false, 'retryAfter' => 0];
        }

        $currentKey = $this->budgetCooldownKey($policy->getName(), $accountId, $this->secret);
        $currentMarker = $this->store->get($currentKey);
        if ($currentMarker !== null) {
            return [
                'issued' => false,
                'retryAfter' => max(0, $currentMarker->updatedAt + $cooldownSeconds - $this->clock->now()->getTimestamp()),
            ];
        }

        if ($this->hasPreviousGeneration($device)) {
            $previousKey = $this->budgetCooldownKey(
                $policy->getName(),
                $accountId,
                $this->previousSecret ?? $this->secret,
            );
            if ($previousKey !== $currentKey) {
                $previousMarker = $this->store->get($previousKey);
                if ($previousMarker !== null) {
                    return [
                        'issued' => false,
                        'retryAfter' => max(0, $previousMarker->updatedAt + $cooldownSeconds - $this->clock->now()->getTimestamp()),
                    ];
                }
            }
        }

        // This is the issuance gate. It must stay atomic and must not be
        // replaced with get()+set().
        $value = $this->store->increment($currentKey, $cooldownSeconds);
        if ($value !== 1) {
            return ['issued' => false, 'retryAfter' => 0];
        }

        return ['issued' => true, 'retryAfter' => max(0, $cooldownSeconds)];
    }

    private function budgetCooldownKey(string $policyName, string $accountId, string $secret): string
    {
        return $this->hashKey(
            "{$policyName}:rate_limiter:budget_cooldown:v1:{$this->envScope}:{$accountId}",
            $secret,
        );
    }

    /**
     * Derive an account-only auxiliary state key without exposing the subject
     * to either storage boundary.
     */
    private function auxiliaryAccountKey(
        string $policyName,
        string $purpose,
        string $accountId,
        string $secret,
    ): string {
        return $this->hashKey(
            "{$policyName}:rate_limiter:aux:{$purpose}:v1:{$this->envScope}:{$accountId}",
            $secret,
        );
    }

    /**
     * Derive the previous outer-key generation only when that generation exists.
     */
    private function previousAuxiliaryAccountKey(
        string $policyName,
        string $purpose,
        string $accountId,
    ): ?string {
        return $this->previousSecret === null
            ? null
            : $this->auxiliaryAccountKey($policyName, $purpose, $accountId, $this->previousSecret);
    }

    private function isKnownForAccount(DeviceIdentityDTO $device): bool
    {
        return $device->isTrustedSession || $device->isDevicePreviouslyVerifiedForAccount;
    }

    private function isCredentialSprayPolicy(string $policyName): bool
    {
        return in_array($policyName, ['login_protection', 'otp_protection'], true);
    }

    private function isDistributedAccountPolicy(string $policyName): bool
    {
        return $this->isCredentialSprayPolicy($policyName);
    }

    private function isTrustedAuthenticationPolicy(string $policyName, DeviceIdentityDTO $device): bool
    {
        return $device->isTrustedSession && $this->isCredentialSprayPolicy($policyName);
    }

    private function isK1Key(string $keyType): bool
    {
        return $keyType === 'k1';
    }

    private function isApiHeavyPolicy(string $policyName): bool
    {
        return $policyName === 'api_heavy_protection';
    }

    private function isApiHeavyKeyType(string $keyType): bool
    {
        return $this->isK1Key($keyType) || in_array($keyType, ['k2', 'k3'], true);
    }

    private function isCanonicalApiHeavyEnforcementKeyType(string $keyType): bool
    {
        return in_array($keyType, ['k1', 'k2', 'k3'], true);
    }

    // --- Budget Key-Rotation Helpers ---
    /**
     * Resolve the single logical budget key across key rotation: V2 wins,
     * V1 is the fallback, never a merge. Returns null when neither version
     * holds a valid (non-expired) budget.
     *
     * @see docs/KEY_STRATEGY.md §4.3.1
     */
    private function resolveActiveBudgetState(?string $keyV2, ?string $keyV1): ?BudgetStateDTO
    {
        if ($keyV2 !== null) {
            $current = $this->store->getBudget($keyV2);
            if ($current !== null) {
                return $current;
            }
        }

        if ($keyV1 !== null) {
            return $this->store->getBudget($keyV1);
        }

        return null;
    }

    private function resolveActiveBudgetKeyV2ThenV1(?string $keyV2, ?string $keyV1): ?string
    {
        if ($keyV2 !== null && $this->store->getBudget($keyV2) !== null) {
            return $keyV2;
        }

        if ($keyV1 !== null && $this->store->getBudget($keyV1) !== null) {
            return $keyV1;
        }

        return null;
    }

    /**
     * Increment a budget counter across key rotation, migrating V1 epoch
     * state into V2 atomically when required. This is used by both the K4
     * account budget and the K5 micro-cap budget.
     *
     * - Active V2 → normal V2 increment (V1 ignored).
     * - No V2 + valid V1 → atomic seed + increment into V2 via
     *   BudgetSeedStoreInterface; explicit failure when the capability is
     *   missing (silent reset is forbidden).
     * - Neither valid → normal V2 increment.
     *
     * @see docs/KEY_STRATEGY.md §4.3.2
     */
    private function incrementBudgetAcrossRotation(string $keyV2, ?string $keyV1, int $amount = 1): BudgetStateDTO
    {
        $v2State = $this->store->getBudget($keyV2);
        if ($v2State !== null) {
            return $this->store->incrementBudget($keyV2, self::BUDGET_EPOCH_SECONDS, $amount);
        }

        if ($keyV1 !== null) {
            $v1State = $this->store->getBudget($keyV1);
            if ($v1State !== null) {
                if (! $this->store instanceof BudgetSeedStoreInterface) {
                    throw new RateLimiterException(
                        'Budget rotation migration requires the BudgetSeedStoreInterface capability; '
                        . 'the configured store cannot carry the previous-secret budget state into V2 without a silent reset.',
                    );
                }

                return $this->store->incrementBudgetWithSeed($keyV2, self::BUDGET_EPOCH_SECONDS, $v1State, $amount);
            }
        }

        return $this->store->incrementBudget($keyV2, self::BUDGET_EPOCH_SECONDS, $amount);
    }

    private function hasPreviousGeneration(DeviceIdentityDTO $device): bool
    {
        return $this->previousSecret !== null || $device->previousFingerprintHash !== null;
    }

    private function previousFingerprintHash(DeviceIdentityDTO $device): ?string
    {
        return $device->previousFingerprintHash ?? $device->fingerprintHash;
    }

    // --- Helpers (Same as before) ---
    private function calculateDecayedScore(int $value, int $updatedAt, string $keyType, string $key): int
    {
        $decayAmount = $this->decayCalculator->calculateDecay(
            $value,
            $updatedAt,
            $this->currentBlockLevel($key),
            $this->scopeForKeyType($keyType),
        );

        return max(0, $value - $decayAmount);
    }

    private function scoreDecayRetryAfter(
        PipelineScoreDTO $score,
        string $key,
        string $keyType,
        int $exitThreshold,
    ): int {
        return $this->decayCalculator->secondsUntilBelowThreshold(
            $score->value,
            $score->updatedAt,
            $this->currentBlockLevel($key),
            $this->scopeForKeyType($keyType),
            $exitThreshold,
        );
    }

    private function currentBlockLevel(string $key): int
    {
        $block = $this->store->checkBlock($key);

        return $block === null ? 0 : $block->level;
    }

    private function scopeForKeyType(string $keyType): string
    {
        return match ($keyType) {
            'k4' => 'account',
            'k3', 'k5' => 'device',
            default => 'ip',
        };
    }
    // ... other helpers identical to previous turn ...

    /**
     * @return array<string, string|null>
     */
    private function buildKeys(RateLimitContextDTO $context, string $ua, ?string $fpHash, string $policyName, string $secret): array
    {
        // Enforce strict Key Strategy namespacing:
        // {policy}:rate_limiter:{type}:{algo_ver}:{env}:{scope_val}
        $base = "{$policyName}:rate_limiter";
        $ver = "v2"; // Current algo version
        $env = $this->envScope;

        $k1 = $this->hashKey("{$base}:k1:{$ver}:{$env}:{$this->getIpPrefix($context->ip)}", $secret);
        $k2 = $this->hashKey("{$base}:k2:{$ver}:{$env}:{$this->getIpPrefix($context->ip)}:{$ua}", $secret);
        $k3 = $fpHash ? $this->hashKey("{$base}:k3:{$ver}:{$env}:{$this->getIpPrefix($context->ip)}:{$fpHash}", $secret) : null;
        $k4 = $context->accountId ? $this->hashKey("{$base}:k4:{$ver}:{$env}:{$context->accountId}", $secret) : null;
        $k5 = $context->accountId && $fpHash ? $this->hashKey("{$base}:k5:{$ver}:{$env}:{$context->accountId}:{$fpHash}", $secret) : null;

        return ['k1' => $k1, 'k2' => $k2, 'k3' => $k3, 'k4' => $k4, 'k5' => $k5];
    }

    /**
     * @param   array<string, string|null>  $keysV2
     * @param   array<string, string|null>  $keysV1
     *
     * @return array<string, ?PipelineScoreDTO>
     */
    private function fetchScores(array $keysV2, array $keysV1): array
    {
        $scores = [];
        foreach ($keysV2 as $keyType => $key) {
            $data = $key ? $this->store->get($key) : null;
            $isFromV1 = false;
            if (! $data && isset($keysV1[$keyType]) && $keysV1[$keyType]) {
                $data = $this->store->get($keysV1[$keyType]);
                if ($data) {
                    $isFromV1 = true;
                }
            }
            $scores[$keyType] = $data ? new PipelineScoreDTO($data->value, $data->updatedAt, $isFromV1) : null;
        }

        return $scores;
    }

    /**
     * @param   array<string, ?PipelineScoreDTO>  $rawScores
     * @param   array<string, string|null>        $keys
     *
     * @return array<string, int>
     */
    private function applyDecay(array $rawScores, array $keys): array
    {
        $decayed = [];
        foreach ($keys as $keyType => $key) {
            $dto = $rawScores[$keyType] ?? null;
            if (! $key || ! $dto) {
                $decayed[$keyType] = 0;
                continue;
            }
            $decayed[$keyType] = $this->calculateDecayedScore($dto->value, $dto->updatedAt, $keyType, $key);
        }

        return $decayed;
    }

    /**
     * @return array{k1: int, k2: int, k3: int, k4: int, k5: int}
     */
    private function calculateDeltas(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        RateLimitCommand $request,
        bool $isEphemeral,
    ): array {
        $deltasDto = $policy->getScoreDeltas();
        // Fix Error 3: Initialize with stable shape
        $result = ['k1' => 0, 'k2' => 0, 'k3' => 0, 'k4' => 0, 'k5' => 0];

        if ($deltasDto->access > 0) {
            $cost = $deltasDto->access * $request->cost;
            $result['k1'] += $cost;
            $result['k2'] += $cost;
            $result['k3'] += $cost;
        }
        if ($request->isFailure) {
            if (empty($device->fingerprintHash)) {
                // Missing-fingerprint scoring is independent from the
                // Login/OTP new-device classification: one failure updates
                // both K4 and K2 when their deltas are configured.
                if ($deltasDto->k4_failure > 0) {
                    $result['k4'] = $deltasDto->k4_failure;
                }
                if ($deltasDto->k2_missing_fp > 0) {
                    $result['k2'] = $deltasDto->k2_missing_fp;
                }
            } elseif ($deltasDto->k4_failure > 0 || $deltasDto->k5_failure > 0) {
                // Authentication-style policies express known/new-device
                // classification through their K4/K5 failure deltas. API
                // Heavy keeps its existing access/spray behavior because it
                // has no authentication failure deltas.
                if ($isEphemeral && ! $this->isApiHeavyPolicy($policy->getName()) && $deltasDto->k4_failure > 0) {
                    // An ephemeral overflow fingerprint has been rejected by
                    // the bounded device cap. Keep the authentication failure
                    // on the account path without creating any K5 identity or
                    // known-device micro-cap state.
                    $result['k4'] = $deltasDto->k4_failure;
                } elseif ($this->isKnownForAccount($device)) {
                    if ($deltasDto->k5_failure > 0) {
                        $result['k5'] = $deltasDto->k5_failure;
                    }
                } elseif ($deltasDto->k4_failure > 0) {
                    $result['k4'] = $deltasDto->k4_failure;
                }
            }
            if ($deltasDto->k1_spray > 0) {
                $result['k1'] = $deltasDto->k1_spray;
            }
        }

        return $result;
    }

    private function getScopedThresholds(string $keyType, BlockPolicyInterface $policy): ?ScoreThresholdsDTO
    {
        $thresholds = $policy->getScoreThresholds();

        return match ($keyType) {
            'k1' => $thresholds->k1,
            'k2' => $thresholds->k2,
            'k3' => $thresholds->k3,
            'k4' => $thresholds->k4,
            'k5' => $thresholds->k5,
            default => $thresholds->default,
        };
    }

    /**
     * @return array<int, int>
     */
    private function normalizedWatchThresholds(ScoreThresholdsDTO $thresholds): array
    {
        $levelsByThreshold = [];
        foreach ([1 => $thresholds->l1, 2 => $thresholds->l2, 3 => $thresholds->l3] as $level => $threshold) {
            if ($threshold === PHP_INT_MAX) {
                continue;
            }

            $levelsByThreshold[$threshold] = max($levelsByThreshold[$threshold] ?? 0, $level);
        }

        return $levelsByThreshold;
    }

    private function determineLevel(int $score, string $keyType, BlockPolicyInterface $policy): int
    {
        $dto = $this->getScopedThresholds($keyType, $policy);
        if (! $dto) {
            return 0;
        }

        if ($score >= $dto->l3) {
            return 3;
        }
        if ($score >= $dto->l2) {
            return 2;
        }
        if ($score >= $dto->l1) {
            return 1;
        }

        return 0;
    }

    private function hashKey(string $input, string $secret): string
    {
        return hash_hmac('sha256', $input, $secret);
    }

    private function getIpPrefix(string $ip, int $cidr = 64): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $hex = bin2hex($packed);
                $length = (int) ceil($cidr / 4);

                return substr($hex, 0, $length);
            }
        }

        return $ip;
    }

    private function createBlockedResult(int $level, int $retryAfter, string $decision): RateLimitResultDTO
    {
        return new RateLimitResultDTO($decision, $level, $retryAfter, 'NORMAL', null);
    }

    private function createAllowResult(): RateLimitResultDTO
    {
        return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, 'NORMAL', null);
    }
}
