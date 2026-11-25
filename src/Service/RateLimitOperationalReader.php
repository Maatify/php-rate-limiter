<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalBudgetDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalKeyStateDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalScopesDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Reads current and previous-generation state for operational inspection.
 *
 * This reader is intentionally read-only: it derives keys and fetches state
 * without incrementing counters, issuing cooldowns, or persisting blocks. Over a
 * store implementing OperationalReadOnlyStoreInterface, blocks and budgets are
 * read without a write: an expired key stays where it is (#185, F-185-26,
 * DEC-032). buildOperationalReader() requires that store; constructed directly
 * over a store without it, the reader keeps checkBlock()/getBudget() (DEC-012).
 */
final class RateLimitOperationalReader implements RateLimitOperationalReaderInterface
{
    /** @var list<object> DEC-041: the resources every independent call of this entry owns. */
    private readonly array $resources;

    private readonly E1AccountScopeKeyDeriver $accountKeys;

    /** The captured account view for all account-bearing keys. */
    private readonly RateLimitStoreInterface $accountStore;

    /** DEC-041: the context domain they are bound to; holds its provider for the lifetime of this object. */
    private readonly ContextDomain $domain;

    private const BUDGET_EPOCH_SECONDS = 86400;

    /**
     * @param DeviceIdentityResolverInterface $deviceResolver Device identity resolver.
     * @param RateLimitStoreInterface $store Score, block, budget, and health store.
     * @param CircuitBreakerStoreInterface $circuitBreakerStore Circuit state store.
     * @param DecayCalculator $decayCalculator Score decay service.
     * @param ClockInterface $clock Source of observation timestamps.
     * @param string $keySecret Active, non-blank key-generation secret. Not
     *     trimmed or otherwise normalized: accepted surrounding whitespace is
     *     retained byte-for-byte.
     * @param string $envScope Non-blank environment namespace included in keys.
     * @param ?string $previousKeySecret Optional previous-generation secret;
     *     null means no previous generation. When provided, it must not be
     *     empty or whitespace-only, and is likewise never trimmed or normalized.
     * @param ?E1StoreBinding $binding Internal Builder handoff of a closed, already validated root/view pair; never a Host option.
     * @throws RateLimiterException When $keySecret or $envScope is empty or
     *     whitespace-only, or when a non-null $previousKeySecret is empty or
     *     whitespace-only.
     */
    public function __construct(
        private readonly DeviceIdentityResolverInterface $deviceResolver,
        private readonly RateLimitStoreInterface $store,
        private readonly CircuitBreakerStoreInterface $circuitBreakerStore,
        private readonly DecayCalculator $decayCalculator,
        private readonly ClockInterface $clock,
        private readonly string $keySecret,
        private readonly string $envScope,
        private readonly ?string $previousKeySecret = null,
        ?E1StoreBinding $binding = null,
    ) {
        if (trim($keySecret) === '') {
            throw new RateLimiterException('Active key secret must not be empty or whitespace-only.');
        }
        if (trim($envScope) === '') {
            throw new RateLimiterException('Environment scope must not be empty or whitespace-only.');
        }
        if ($previousKeySecret !== null && trim($previousKeySecret) === '') {
            throw new RateLimiterException('Previous key secret must not be empty or whitespace-only.');
        }
        if (func_num_args() > 9) {
            throw new \ArgumentCountError('The reader does not accept a key deriver.');
        }
        $this->accountKeys = new E1AccountScopeKeyDeriver($this->envScope);
        $accountStore = ($binding ?? E1StoreBinding::capture($this->store))->forRoot($this->store);
        if (! $accountStore instanceof RateLimitStoreInterface) {
            throw new RateLimiterException('The account view must preserve the rate-limit storage role.');
        }
        $this->accountStore = $accountStore;
        $this->resources = OwnershipRegistry::collect([$this->store, $this->accountStore, $this->circuitBreakerStore], [], [$this->clock]);
        $this->domain = OwnershipRegistry::adopt($this->resources, $this);
    }

    /**
     * Read one policy's scores, blocks, budgets, circuit state, and health.
     *
     * The optional admission lane selects the key family and K5 material the
     * state was written under by the evaluation pipeline (K4P / K5p and the
     * `_p` budget cooldown on the proven lane; the instance-id material when
     * the device carries one). `null` is today's read. This concrete method
     * only: the public reader interface is unchanged, so no Host can ask for a
     * lane before the admission runtime exists.
     */
    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy,
        ?AdmissionLaneEnum $lane = null,
    ): RateLimitOperationalSnapshotDTO {
        $scope = OwnershipRegistry::acquire($this->resources, 'read', $this->domain);
        try {
            return $this->readOwned($context, $policy, $lane);
        } finally {
            $scope->release();
        }
    }

    private function readOwned(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy,
        ?AdmissionLaneEnum $lane = null,
    ): RateLimitOperationalSnapshotDTO {
        $device = OwnershipRegistry::crossing($this->store, fn() => $this->deviceResolver->resolve($context));
        $currentKeys = $this->buildKeys(
            $context,
            $device->normalizedUa,
            $device->fingerprintHash,
            $this->hostPolicy(fn() => $policy->getName()),
            $this->keySecret,
            $lane,
            $this->k5Material($device, $lane),
        );
        $previousKeys = $this->hasPreviousGeneration($device)
            ? $this->buildKeys(
                $context,
                $device->normalizedUa,
                $this->previousFingerprintHash($device),
                $this->hostPolicy(fn() => $policy->getName()),
                $this->previousKeySecret ?? $this->keySecret,
                $lane,
                $this->previousK5Material($device, $lane),
            )
            : [];

        $backendHealthy = $this->store->isHealthy();
        $scopes = $this->buildScopes($currentKeys, $previousKeys);
        $budget = $this->buildBudget($context, $device, $policy, $currentKeys, $previousKeys, $lane);
        $circuitBreaker = $this->circuitBreakerStore->load($this->hostPolicy(fn() => $policy->getName()));

        return new RateLimitOperationalSnapshotDTO(
            $this->hostPolicy(fn() => $policy->getName()),
            $this->hostNow()->getTimestamp(),
            $backendHealthy,
            $scopes,
            $budget,
            $circuitBreaker,
        );
    }

    /**
     * @param array<string, string|null> $currentKeys
     * @param array<string, string|null> $previousKeys
     */
    private function buildScopes(array $currentKeys, array $previousKeys): RateLimitOperationalScopesDTO
    {
        return new RateLimitOperationalScopesDTO(
            $this->readKeyState('k1', $currentKeys['k1'], $previousKeys['k1'] ?? null),
            $this->readKeyState('k2', $currentKeys['k2'], $previousKeys['k2'] ?? null),
            $this->readOptionalKeyState('k3', $currentKeys['k3'], $previousKeys['k3'] ?? null),
            $this->readOptionalKeyState('k4', $currentKeys['k4'], $previousKeys['k4'] ?? null),
            $this->readOptionalKeyState('k5', $currentKeys['k5'], $previousKeys['k5'] ?? null),
        );
    }

    private function readOptionalKeyState(
        string $keyType,
        ?string $currentKey,
        ?string $previousKey,
    ): ?RateLimitOperationalKeyStateDTO {
        if ($currentKey === null) {
            return null;
        }

        return $this->readKeyState($keyType, $currentKey, $previousKey);
    }

    private function readKeyState(string $keyType, ?string $currentKey, ?string $previousKey): RateLimitOperationalKeyStateDTO
    {
        if ($currentKey === null) {
            throw new \LogicException('Required operational scope key is unavailable.');
        }

        $store = $keyType === 'k4' || $keyType === 'k5' ? $this->accountStore : $this->store;
        $score = $store->get($currentKey);
        $scoreFromPreviousGeneration = false;
        if ($score === null && $previousKey !== null) {
            $score = $store->get($previousKey);
            $scoreFromPreviousGeneration = $score !== null;
        }

        // Use the active logical block level across Current/Previous, matching
        // the pipeline's rotation-aware decay semantics.
        $now = $this->hostNow()->getTimestamp();
        $currentBlock = CallerTimeStore::operationalBlock($store, $currentKey, $this->hostNow());
        $activeHardBlock = $this->activeHardBlock($currentBlock, $now);
        $blockFromPreviousGeneration = false;
        $previousBlock = null;

        if ($activeHardBlock === null && $previousKey !== null) {
            $previousBlock = CallerTimeStore::operationalBlock($store, $previousKey, $this->hostNow());
            $activeHardBlock = $this->activeHardBlock($previousBlock, $now);
            $blockFromPreviousGeneration = $activeHardBlock !== null;
        }

        $effectiveScore = 0;
        if ($score !== null) {
            $decayLevel = $this->activeBlockLevel($currentBlock, $previousBlock, $now);
            $decayScope = match ($keyType) {
                'k4' => 'account',
                'k3', 'k5' => 'device',
                default => 'ip',
            };
            $pauseState = $store instanceof HardBlockCycleStoreInterface
                ? $store->readDecayPauseState($currentKey, $previousKey, $score->updatedAt, $now)
                : new DecayPauseStateDTO(0, 0);
            $decayAmount = $this->decayCalculator->calculateDecay(
                $score->value,
                $score->updatedAt,
                $decayLevel,
                $decayScope,
                $pauseState->elapsedPausedSeconds,
            );
            $effectiveScore = max(0, $score->value - $decayAmount);
        }

        return new RateLimitOperationalKeyStateDTO(
            $score,
            $effectiveScore,
            $scoreFromPreviousGeneration,
            $activeHardBlock,
            $blockFromPreviousGeneration,
        );
    }

    private function activeHardBlock(?BlockStateDTO $block, int $now): ?BlockStateDTO
    {
        return $block !== null && $block->level >= 2 && $block->expiresAt > $now ? $block : null;
    }

    private function activeBlockLevel(?BlockStateDTO $current, ?BlockStateDTO $previous, int $now): int
    {
        if ($current !== null && $current->expiresAt > $now) {
            return $current->level;
        }
        if ($previous !== null && $previous->expiresAt > $now) {
            return $previous->level;
        }

        return 0;
    }

    /**
     * @param array<string, string|null> $currentKeys
     * @param array<string, string|null> $previousKeys
     */
    private function buildBudget(
        RateLimitContextDTO $context,
        DeviceIdentityDTO $device,
        BlockPolicyInterface $policy,
        array $currentKeys,
        array $previousKeys,
        ?AdmissionLaneEnum $lane,
    ): ?RateLimitOperationalBudgetDTO {
        $config = $this->hostPolicy(fn() => $policy->getBudgetConfig());
        if ($config === null || ! IdentityPresence::account($context)) {
            return null;
        }

        [$accountBudget, $accountBudgetFromPreviousGeneration] = $this->resolveBudgetState(
            $currentKeys['k4'] ?? null,
            $previousKeys['k4'] ?? null,
        );
        $accountBudgetActive = $accountBudget !== null
            && $accountBudget->count >= $config->threshold
            && $this->isWithinBudgetEpoch($accountBudget);

        $microCap = null;
        $microCapFromPreviousGeneration = false;
        $microCapExceeded = false;
        if ($config->known_device_micro_cap !== null && ($currentKeys['k5'] ?? null) !== null) {
            [$microCap, $microCapFromPreviousGeneration] = $this->resolveBudgetState(
                $this->microCapKey($this->hostPolicy(fn() => $policy->getName()), $context->accountId, $this->k5Material($device, $lane), $this->keySecret, $lane),
                $this->hasPreviousGeneration($device)
                    ? $this->microCapKey(
                        $this->hostPolicy(fn() => $policy->getName()),
                        $context->accountId,
                        $this->previousK5Material($device, $lane),
                        $this->previousKeySecret ?? $this->keySecret,
                        $lane,
                    )
                    : null,
            );
            $microCapExceeded = $microCap !== null && $microCap->count > $config->known_device_micro_cap;
        }

        [$cooldown, $cooldownFromPreviousGeneration] = $this->resolveCooldown(
            $this->hostPolicy(fn() => $policy->getName()),
            $context->accountId,
            $device,
            $lane,
        );
        $cooldownRemainingSeconds = $cooldown === null
            ? 0
            : max(0, $cooldown->updatedAt + $config->cooldown_seconds - $this->hostNow()->getTimestamp());

        return new RateLimitOperationalBudgetDTO(
            $accountBudget,
            $accountBudgetFromPreviousGeneration,
            $accountBudgetActive,
            $microCap,
            $microCapFromPreviousGeneration,
            $microCapExceeded,
            $cooldown,
            $cooldownFromPreviousGeneration,
            $cooldownRemainingSeconds,
        );
    }

    /**
     * @return array{0: ?BudgetStateDTO, 1: bool}
     */
    private function resolveBudgetState(?string $currentKey, ?string $previousKey): array
    {
        if ($currentKey !== null) {
            $current = CallerTimeStore::operationalBudget($this->accountStore, $currentKey, $this->hostNow());
            if ($current !== null) {
                return [$current, false];
            }
        }

        if ($previousKey !== null) {
            $previous = CallerTimeStore::operationalBudget($this->accountStore, $previousKey, $this->hostNow());
            if ($previous !== null) {
                return [$previous, true];
            }
        }

        return [null, false];
    }

    /**
     * @return array{0: ?RateLimitStateDTO, 1: bool}
     */
    private function resolveCooldown(string $policyName, string $accountId, DeviceIdentityDTO $device, ?AdmissionLaneEnum $lane): array
    {
        $currentKey = $this->budgetCooldownKey($policyName, $accountId, $this->keySecret, $lane);
        $current = $this->accountStore->get($currentKey);
        if ($current !== null) {
            return [$current, false];
        }

        if ($this->hasPreviousGeneration($device)) {
            $previousKey = $this->budgetCooldownKey(
                $policyName,
                $accountId,
                $this->previousKeySecret ?? $this->keySecret,
                $lane,
            );
            if ($previousKey !== $currentKey) {
                $previous = $this->accountStore->get($previousKey);
                if ($previous !== null) {
                    return [$previous, true];
                }
            }
        }

        return [null, false];
    }

    private function isWithinBudgetEpoch(BudgetStateDTO $state): bool
    {
        return $state->epochStart + self::BUDGET_EPOCH_SECONDS > $this->hostNow()->getTimestamp();
    }

    private function microCapKey(string $policyName, string $accountId, ?string $k5Material, string $secret, ?AdmissionLaneEnum $lane): ?string
    {
        if ($k5Material === null) {
            return null;
        }

        return $this->accountKeys->derive($lane === AdmissionLaneEnum::P ? AccountScopePurpose::MICROCAP_K5P : AccountScopePurpose::MICROCAP_K5, $policyName, $accountId, $secret, $k5Material);
    }

    private function budgetCooldownKey(string $policyName, string $accountId, string $secret, ?AdmissionLaneEnum $lane): string
    {
        return $this->accountKeys->derive($this->laneAuxiliaryPurpose($lane, 'budget_cooldown'), $policyName, $accountId, $secret);
    }

    // The five helpers below are the reader's own copy of the pipeline's lane rules (same names, same meaning);
    // READER-PARITY is the gate that keeps them equal.

    /** Account key type: K4P on the proven lane, K4 otherwise. */
    private function k4KeyType(?AdmissionLaneEnum $lane): string
    {
        return $lane === AdmissionLaneEnum::P ? 'k4p' : 'k4';
    }

    /** Device key type: K5p on the proven lane, K5 otherwise. */
    private function k5KeyType(?AdmissionLaneEnum $lane): string
    {
        return $lane === AdmissionLaneEnum::P ? 'k5p' : 'k5';
    }

    /** Auxiliary purpose of the lane: the proven family is the unsuffixed purpose with `_p` appended. */
    private function laneAuxiliaryPurpose(?AdmissionLaneEnum $lane, string $purpose): string
    {
        return $lane === AdmissionLaneEnum::P ? $purpose . '_p' : $purpose;
    }

    /** K5 material: with a lane the instance-id component when present, else the fingerprint; without a lane the fingerprint. */
    private function k5Material(DeviceIdentityDTO $device, ?AdmissionLaneEnum $lane): ?string
    {
        return $lane === null ? $device->fingerprintHash : ($device->deviceIdHash ?? $device->fingerprintHash);
    }

    /** Previous generation of the K5 material: the same material by the previous hasher; no id or no lane keeps the fingerprint generation. */
    private function previousK5Material(DeviceIdentityDTO $device, ?AdmissionLaneEnum $lane): ?string
    {
        return $lane === null || $device->deviceIdHash === null
            ? $this->previousFingerprintHash($device)
            : ($device->previousDeviceIdHash ?? $device->deviceIdHash);
    }

    private function hasPreviousGeneration(DeviceIdentityDTO $device): bool
    {
        return $this->previousKeySecret !== null || $device->previousFingerprintHash !== null;
    }

    private function previousFingerprintHash(DeviceIdentityDTO $device): ?string
    {
        return $device->previousFingerprintHash ?? $device->fingerprintHash;
    }

    /**
     * @return array<string, string|null>
     */
    private function buildKeys(
        RateLimitContextDTO $context,
        string $ua,
        ?string $fingerprintHash,
        string $policyName,
        string $secret,
        ?AdmissionLaneEnum $lane = null,
        ?string $k5Material = null,
    ): array {
        $base = "{$policyName}:rate_limiter";
        $version = 'v2';
        $environment = $this->envScope;

        $keys = [
            'k1' => $this->hashKey("{$base}:k1:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}", $secret),
            'k2' => $this->hashKey("{$base}:k2:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}:{$ua}", $secret),
            'k3' => ! IdentityPresence::isPresent($fingerprintHash)
                ? null
                : $this->hashKey("{$base}:k3:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}:{$fingerprintHash}", $secret),
            'k4' => ! IdentityPresence::account($context)
                ? null
                : $this->accountKeys->derive($this->k4KeyType($lane), $policyName, $context->accountId, $secret),
            'k5' => ! IdentityPresence::account($context) || ! IdentityPresence::isPresent($fingerprintHash)
                ? null
                : $this->accountKeys->derive($this->k5KeyType($lane), $policyName, $context->accountId, $secret, $k5Material ?? $fingerprintHash),
        ];

        return $keys;
    }

    private function hashKey(string $input, string $secret): string
    {
        return hash_hmac('sha256', $input, $secret);
    }

    private function getIpPrefix(string $ip, int $cidr = 64): string
    {
        return SourcePrefix::of($ip, $cidr);
    }

    /**
     * DEC-041: a policy getter is Host code: a call it makes into the library from this stack is a re-entry, not a nested operation.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function hostPolicy(callable $call): mixed
    {
        return OwnershipRegistry::crossing($this->store, $call);
    }

    /** DEC-041: the Host clock is a crossing of the call that owns this reader's store. */
    private function hostNow(): \DateTimeImmutable
    {
        return OwnershipRegistry::crossing($this->store, fn(): \DateTimeImmutable => $this->clock->now());
    }
}
