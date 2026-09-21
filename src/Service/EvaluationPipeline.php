<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
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

        // 4. Resolve Effective Keys (Ephemeral Logic) for Scoring/Updates
        $effectiveHash = $device->fingerprintHash;
        if ($device->fingerprintHash) {
            // resolveKey returns string (real or ephemeral key)
            $effectiveHash = $this->ephemeralBucket->resolveKey($context, $device->fingerprintHash);
        }
        $effectiveKeysV2 = $this->buildKeys($context, $device->normalizedUa, $effectiveHash, $policy->getName(), $this->secret);
        $effectiveKeysV1 = $this->hasPreviousGeneration($device)
            ? $this->buildKeys(
                $context,
                $device->normalizedUa,
                $this->previousFingerprintHash($device),
                $policy->getName(),
                $this->previousSecret ?? $this->secret,
            )
            : [];

        // Check state just for knowing if it IS ephemeral (for key filtering)
        // Since resolveKey already did the counting/check, we can infer from the key string or call check() to get DTO.
        // Calling check() is idempotent for sets.
        $ephemeralState = $device->fingerprintHash
            ? $this->ephemeralBucket->check($context, $device->fingerprintHash)
            : null;
        $isEphemeral = false;
        if ($ephemeralState !== null) {
            $isEphemeral = $ephemeralState->isEphemeral;
        }

        if ($isEphemeral) {
            unset($effectiveKeysV2['k3'], $effectiveKeysV2['k5']);
            unset($effectiveKeysV1['k3'], $effectiveKeysV1['k5']);
        }

        // 5. Fetch & Decay Scores (Using Effective Keys)
        $rawScores = $this->fetchScores($effectiveKeysV2, $effectiveKeysV1);
        $decayedScores = $this->applyDecay($rawScores, $effectiveKeysV2);

        // 6. Evaluate normal candidates. None of these candidates may be
        // hidden by an active account budget.
        $candidates = $this->checkThresholds($policy, $rawScores, $decayedScores, $effectiveKeysV2, $device);

        // 7. Check Correlation Rules
        if ($candidate = $this->checkCorrelationRules($context, $device, $policy->getName(), $isEphemeral)) {
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
            )) {
                $candidates[] = $candidate;
            }
        }

        // 8. New Device Flood (5.4)
        if ($ephemeralState && $context->accountId && ! $this->isApiHeavyPolicy($policy->getName())) {
            if ($ephemeralState->accountDeviceCount >= 6) {
                $floodKey = "flood_stage:acc:{$context->accountId}";
                $isFloodStage = $this->correlationStore->getWatchFlag($floodKey) > 0;

                if ($isFloodStage) {
                    $duration = PenaltyLadder::getDuration(2);
                    $persistence = [];
                    if ($realKeysV2['k5'] !== null) {
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
        }

        // 9. Process Updates (Failure / Access). Budget counting is part of
        // this step and must continue even while BudgetActive.
        $budgetState = $this->resolveActiveBudgetState($realKeysV2['k4'] ?? null, $realKeysV1['k4'] ?? null);
        $budgetRequestEligible = false;
        $budgetSuppressed = false;
        if (! $request->isPreCheck && ($request->isFailure || $policy->getScoreDeltas()->access > 0)) {
            // We write only to V2 (Active Key); V1 stays read-only
            $updates = $this->processUpdates($policy, $context, $request, $device, $effectiveKeysV2, $effectiveKeysV1, $rawScores);
            $candidates = array_merge($candidates, $updates['candidates']);
            $budgetState = $updates['budgetState'];
            $budgetRequestEligible = $updates['budgetRequestEligible'];
            $budgetSuppressed = $updates['budgetSuppressed'];
        }

        // Anti-Equilibrium reads prior history before budget cooldown
        // acquisition. Recording happens only after final aggregation.
        if ($request->isFailure && $context->accountId !== null && $policy->getBudgetConfig() !== null
            && $this->antiEquilibriumGate->shouldEscalate($context->accountId)) {
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
            $this->antiEquilibriumGate->recordSoftBlock($context->accountId);
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
                if ($this->isApiHeavyPolicy($policy->getName())) {
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
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function checkCorrelationRules(RateLimitContextDTO $context, DeviceIdentityDTO $device, string $policyName, bool $isEphemeral): ?array
    {
        $base = "{$policyName}:rate_limiter";
        $ver = "v2";
        $env = $this->envScope;

        $k2 = $this->hashKey("{$base}:k2:{$ver}:{$env}:{$this->getIpPrefix($context->ip)}:{$device->normalizedUa}", $this->secret);

        if ($device->fingerprintHash) {
            $count = $this->correlationStore->addDistinct("churn:{$k2}", $device->fingerprintHash, 600);
            if ($count >= 3) {
                return $this->candidate(
                    RateLimitResultDTO::DECISION_HARD_BLOCK,
                    2,
                    60,
                    'correlation',
                    [['key' => $k2, 'level' => 2, 'duration' => 60]],
                );
            }
        }
        if ($device->fingerprintHash) {
            $k3_raw = "dilution:{$device->fingerprintHash}";
            $count = $this->correlationStore->addDistinct($k3_raw, $context->ip, 600);

            $thresholdMet = false;
            if ($count >= 6) {
                $thresholdMet = true;
            } elseif ($count === 5) {
                // Dilution N-1 Watch
                $wKey = "watch_dilution:{$device->fingerprintHash}";
                $flags = $this->correlationStore->incrementWatchFlag($wKey, 1800);
                if ($flags >= 2) {
                    $thresholdMet = true;
                }
            }

            if ($thresholdMet) {
                $targetKey = null;
                $shouldBlock = false;
                if ($device->confidence === 'LOW') {
                    $targetKey = $k2;
                    $shouldBlock = true;
                } else {
                    // Medium+ Confidence requires 2-window confirmation (consecutive 10-minute windows)
                    // We use a window-based key to track presence
                    $windowId = (int) floor($this->clock->now()->getTimestamp() / 600);
                    $prevWindowId = $windowId - 1;

                    $wKey = "dilution_warn:{$device->fingerprintHash}:{$windowId}";
                    $this->correlationStore->incrementWatchFlag($wKey, 1200); // 20 min retention

                    $prevWKey = "dilution_warn:{$device->fingerprintHash}:{$prevWindowId}";
                    $prevCount = $this->correlationStore->getWatchFlag($prevWKey);

                    if ($prevCount > 0) {
                        $targetKey = $this->hashKey("{$base}:k3:{$ver}:{$env}:{$this->getIpPrefix($context->ip)}:{$device->fingerprintHash}", $this->secret);
                        $shouldBlock = true;
                    }
                }

                if ($shouldBlock && $targetKey) {
                    if ($isEphemeral && strpos($targetKey, ':k3:') !== false) {
                        $targetKey = $k2;
                    }

                    return $this->candidate(
                        RateLimitResultDTO::DECISION_HARD_BLOCK,
                        2,
                        60,
                        'correlation',
                        [['key' => $targetKey, 'level' => 2, 'duration' => 60]],
                    );
                }
            }
        }

        return null;
    }

    /**
     * Observe credential spray subjects for authentication pre-checks only.
     *
     * The subject member is always a domain-separated HMAC. Raw account or
     * correlation identities never cross the correlation-store boundary.
     *
     * @return array{decision: string, level: int, retryAfter: int, source: string, persistence: list<array{key: string, level: int, duration: int}>}|null
     */
    private function checkCredentialSpray(
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        string $policyName,
        ?string $k1Key,
        ?string $previousK1Key,
    ): ?array {
        $subject = $context->correlationId ?? $context->accountId;
        if ($subject === null || $k1Key === null) {
            return null;
        }

        $scopeKey = 'credential_spray:' . $k1Key;
        if ($this->previousSecret === null) {
            $member = $this->hashKey('credential_spray:subject:v1:' . $subject, $this->secret);
            $count = $this->correlationStore->addDistinct($scopeKey, $member, 600);
        } else {
            if ($previousK1Key === null) {
                throw new RateLimiterException(
                    'Credential-spray key rotation requires a previous K1 correlation key.',
                );
            }

            if (! $this->correlationStore instanceof CorrelationRotationStoreInterface) {
                throw new RateLimiterException(
                    'Credential-spray key rotation requires the CorrelationRotationStoreInterface capability; '
                    . 'the configured store cannot preserve previous-generation correlation without a silent reset.',
                );
            }

            $member = $this->hashKey('credential_spray:subject:v1:' . $subject, $this->secret);
            $previousMember = $this->hashKey(
                'credential_spray:subject:v1:' . $subject,
                $this->previousSecret,
            );
            $count = $this->correlationStore->addDistinctAcrossRotation(
                $scopeKey,
                'credential_spray:bridge:' . $k1Key,
                'credential_spray:' . $previousK1Key,
                $member,
                $previousMember,
                600,
            );
        }

        if ($this->previousSecret !== null) {
            $this->assertPositiveCorrelationResult($count, 'distinct credential-spray correlation');
        }

        $thresholdMet = $count >= 5;
        if ($count === 4) {
            if ($this->previousSecret === null) {
                $watchCount = $this->correlationStore->incrementWatchFlag($scopeKey . ':watch', 1800);
            } else {
                if ($previousK1Key === null || ! $this->correlationStore instanceof CorrelationRotationStoreInterface) {
                    throw new RateLimiterException(
                        'Credential-spray WATCH rotation requires the CorrelationRotationStoreInterface capability.',
                    );
                }

                $watchCount = $this->correlationStore->incrementWatchFlagAcrossRotation(
                    $scopeKey . ':watch',
                    'credential_spray:' . $previousK1Key . ':watch',
                    1800,
                );
            }
            if ($this->previousSecret !== null) {
                $this->assertPositiveCorrelationResult($watchCount, 'credential-spray WATCH correlation');
            }
            $thresholdMet = $watchCount >= 2;
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

    private function assertPositiveCorrelationResult(int $result, string $operation): void
    {
        if ($result < 1) {
            throw new RateLimiterException("Malformed result from {$operation}: expected a positive count.");
        }
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
        array $keys,
        array $keysV1,
        array $rawScores,
    ): array {
        $deltas = $this->calculateDeltas($policy, $context, $device, $request);

        if ($request->isFailure && empty($device->fingerprintHash) && $context->accountId) {
            $key = "last_missing_fp:acc:{$context->accountId}";
            $last = $this->store->get($key);
            if ($last && ($this->clock->now()->getTimestamp() - $last->value) <= 1800) {
                $k4Repeated = $policy->getScoreDeltas()->k4_repeated_missing_fp;
                if ($k4Repeated > 0) {
                    $deltas['k4'] = $deltas['k4'] + $k4Repeated;
                }
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

            $deltaKey = $keyType;
            if (str_starts_with($keyType, 'k1_')) {
                $deltaKey = 'k1';
            }

            $delta = $deltas[$deltaKey] ?? 0;
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
            if ($deltas['k5'] > 0 && $context->accountId && $device->fingerprintHash
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
                    $persistenceKey = $keys[$candidateKeyType] ?? null;
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

    private function isKnownForAccount(DeviceIdentityDTO $device): bool
    {
        return $device->isTrustedSession || $device->isDevicePreviouslyVerifiedForAccount;
    }

    private function isCredentialSprayPolicy(string $policyName): bool
    {
        return in_array($policyName, ['login_protection', 'otp_protection'], true);
    }

    private function isTrustedAuthenticationPolicy(string $policyName, DeviceIdentityDTO $device): bool
    {
        return $device->isTrustedSession && $this->isCredentialSprayPolicy($policyName);
    }

    private function isK1Key(string $keyType): bool
    {
        return $keyType === 'k1' || str_starts_with($keyType, 'k1_');
    }

    private function isApiHeavyPolicy(string $policyName): bool
    {
        return $policyName === 'api_heavy_protection';
    }

    private function isApiHeavyKeyType(string $keyType): bool
    {
        return $this->isK1Key($keyType) || in_array($keyType, ['k2', 'k3'], true);
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

        $keys = ['k1' => $k1, 'k2' => $k2, 'k3' => $k3, 'k4' => $k4, 'k5' => $k5];
        if (filter_var($context->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $keys['k1_48'] = $this->hashKey("{$base}:k1:{$ver}:{$env}:{$this->getIpPrefix($context->ip, 48)}", $secret);
            $keys['k1_40'] = $this->hashKey("{$base}:k1:{$ver}:{$env}:{$this->getIpPrefix($context->ip, 40)}", $secret);
            $keys['k1_32'] = $this->hashKey("{$base}:k1:{$ver}:{$env}:{$this->getIpPrefix($context->ip, 32)}", $secret);
        }

        return $keys;
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
    private function calculateDeltas(BlockPolicyInterface $policy, RateLimitContextDTO $context, DeviceIdentityDTO $device, RateLimitCommand $request): array
    {
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
                if ($this->isKnownForAccount($device)) {
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

        if (str_starts_with($keyType, 'k1_')) {
            return $thresholds->k1 ?? $thresholds->default;
        }

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
