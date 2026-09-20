<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalBudgetDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalKeyStateDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalScopesDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class RateLimitOperationalReader implements RateLimitOperationalReaderInterface
{
    private const BUDGET_EPOCH_SECONDS = 86400;

    public function __construct(
        private readonly DeviceIdentityResolverInterface $deviceResolver,
        private readonly RateLimitStoreInterface $store,
        private readonly CircuitBreakerStoreInterface $circuitBreakerStore,
        private readonly DecayCalculator $decayCalculator,
        private readonly ClockInterface $clock,
        private readonly string $keySecret,
        private readonly string $envScope,
        private readonly ?string $previousKeySecret = null
    ) {}

    public function read(
        RateLimitContextDTO $context,
        BlockPolicyInterface $policy
    ): RateLimitOperationalSnapshotDTO {
        $device = $this->deviceResolver->resolve($context);
        $currentKeys = $this->buildKeys(
            $context,
            $device->normalizedUa,
            $device->fingerprintHash,
            $policy->getName(),
            $this->keySecret
        );
        $previousKeys = $this->hasPreviousGeneration($device)
            ? $this->buildKeys(
                $context,
                $device->normalizedUa,
                $this->previousFingerprintHash($device),
                $policy->getName(),
                $this->previousKeySecret ?? $this->keySecret
            )
            : [];

        $backendHealthy = $this->store->isHealthy();
        $scopes = $this->buildScopes($currentKeys, $previousKeys);
        $budget = $this->buildBudget($context, $device, $policy, $currentKeys, $previousKeys);
        $circuitBreaker = $this->circuitBreakerStore->load($policy->getName());

        return new RateLimitOperationalSnapshotDTO(
            $policy->getName(),
            $this->clock->now()->getTimestamp(),
            $backendHealthy,
            $scopes,
            $budget,
            $circuitBreaker
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
            $this->readOptionalKeyState('k1_48', $currentKeys['k1_48'] ?? null, $previousKeys['k1_48'] ?? null),
            $this->readOptionalKeyState('k1_40', $currentKeys['k1_40'] ?? null, $previousKeys['k1_40'] ?? null),
            $this->readOptionalKeyState('k1_32', $currentKeys['k1_32'] ?? null, $previousKeys['k1_32'] ?? null)
        );
    }

    private function readOptionalKeyState(
        string $keyType,
        ?string $currentKey,
        ?string $previousKey
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

        $score = $this->store->get($currentKey);
        $scoreFromPreviousGeneration = false;
        if ($score === null && $previousKey !== null) {
            $score = $this->store->get($previousKey);
            $scoreFromPreviousGeneration = $score !== null;
        }

        // EvaluationPipeline applies score decay using the current-generation
        // key for its block-level modifier even when the score came from V1.
        $currentBlock = $this->store->checkBlock($currentKey);
        $activeHardBlock = $this->activeHardBlock($currentBlock);
        $blockFromPreviousGeneration = false;

        if ($activeHardBlock === null && $previousKey !== null) {
            $previousBlock = $this->store->checkBlock($previousKey);
            $activeHardBlock = $this->activeHardBlock($previousBlock);
            $blockFromPreviousGeneration = $activeHardBlock !== null;
        }

        $effectiveScore = 0;
        if ($score !== null) {
            $decayLevel = $currentBlock === null ? 0 : $currentBlock->level;
            $decayScope = match ($keyType) {
                'k4' => 'account',
                'k3', 'k5' => 'device',
                default => 'ip',
            };
            $decayAmount = $this->decayCalculator->calculateDecay(
                $score->value,
                $score->updatedAt,
                $decayLevel,
                $decayScope
            );
            $effectiveScore = max(0, $score->value - $decayAmount);
        }

        return new RateLimitOperationalKeyStateDTO(
            $score,
            $effectiveScore,
            $scoreFromPreviousGeneration,
            $activeHardBlock,
            $blockFromPreviousGeneration
        );
    }

    private function activeHardBlock(?BlockStateDTO $block): ?BlockStateDTO
    {
        return $block !== null && $block->level >= 2 ? $block : null;
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
        array $previousKeys
    ): ?RateLimitOperationalBudgetDTO {
        $config = $policy->getBudgetConfig();
        if ($config === null || $context->accountId === null) {
            return null;
        }

        [$accountBudget, $accountBudgetFromPreviousGeneration] = $this->resolveBudgetState(
            $currentKeys['k4'] ?? null,
            $previousKeys['k4'] ?? null
        );
        $accountBudgetActive = $accountBudget !== null
            && $accountBudget->count >= $config->threshold
            && $this->isWithinBudgetEpoch($accountBudget);

        $microCap = null;
        $microCapFromPreviousGeneration = false;
        $microCapExceeded = false;
        if ($config->known_device_micro_cap !== null && ($currentKeys['k5'] ?? null) !== null) {
            [$microCap, $microCapFromPreviousGeneration] = $this->resolveBudgetState(
                $this->microCapKey($policy->getName(), $context->accountId, $device->fingerprintHash, $this->keySecret),
                $this->hasPreviousGeneration($device)
                    ? $this->microCapKey(
                        $policy->getName(),
                        $context->accountId,
                        $this->previousFingerprintHash($device),
                        $this->previousKeySecret ?? $this->keySecret
                    )
                    : null
            );
            $microCapExceeded = $microCap !== null && $microCap->count > $config->known_device_micro_cap;
        }

        [$cooldown, $cooldownFromPreviousGeneration] = $this->resolveCooldown(
            $policy->getName(),
            $context->accountId,
            $device
        );
        $cooldownRemainingSeconds = $cooldown === null
            ? 0
            : max(0, $cooldown->updatedAt + $config->cooldown_seconds - $this->clock->now()->getTimestamp());

        return new RateLimitOperationalBudgetDTO(
            $accountBudget,
            $accountBudgetFromPreviousGeneration,
            $accountBudgetActive,
            $microCap,
            $microCapFromPreviousGeneration,
            $microCapExceeded,
            $cooldown,
            $cooldownFromPreviousGeneration,
            $cooldownRemainingSeconds
        );
    }

    /**
     * @return array{0: ?BudgetStateDTO, 1: bool}
     */
    private function resolveBudgetState(?string $currentKey, ?string $previousKey): array
    {
        if ($currentKey !== null) {
            $current = $this->store->getBudget($currentKey);
            if ($current !== null) {
                return [$current, false];
            }
        }

        if ($previousKey !== null) {
            $previous = $this->store->getBudget($previousKey);
            if ($previous !== null) {
                return [$previous, true];
            }
        }

        return [null, false];
    }

    /**
     * @return array{0: ?RateLimitStateDTO, 1: bool}
     */
    private function resolveCooldown(string $policyName, string $accountId, DeviceIdentityDTO $device): array
    {
        $currentKey = $this->budgetCooldownKey($policyName, $accountId, $this->keySecret);
        $current = $this->store->get($currentKey);
        if ($current !== null) {
            return [$current, false];
        }

        if ($this->hasPreviousGeneration($device)) {
            $previousKey = $this->budgetCooldownKey(
                $policyName,
                $accountId,
                $this->previousKeySecret ?? $this->keySecret
            );
            if ($previousKey !== $currentKey) {
                $previous = $this->store->get($previousKey);
                if ($previous !== null) {
                    return [$previous, true];
                }
            }
        }

        return [null, false];
    }

    private function isWithinBudgetEpoch(BudgetStateDTO $state): bool
    {
        return $state->epochStart + self::BUDGET_EPOCH_SECONDS > $this->clock->now()->getTimestamp();
    }

    private function microCapKey(string $policyName, string $accountId, ?string $fingerprintHash, string $secret): ?string
    {
        if ($fingerprintHash === null) {
            return null;
        }

        return $this->hashKey(
            "{$policyName}:rate_limiter:microcap:k5:v1:{$accountId}:{$fingerprintHash}",
            $secret
        );
    }

    private function budgetCooldownKey(string $policyName, string $accountId, string $secret): string
    {
        return $this->hashKey(
            "{$policyName}:rate_limiter:budget_cooldown:v1:{$this->envScope}:{$accountId}",
            $secret
        );
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
        string $secret
    ): array {
        $base = "{$policyName}:rate_limiter";
        $version = 'v2';
        $environment = $this->envScope;

        $keys = [
            'k1' => $this->hashKey("{$base}:k1:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}", $secret),
            'k2' => $this->hashKey("{$base}:k2:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}:{$ua}", $secret),
            'k3' => $fingerprintHash === null
                ? null
                : $this->hashKey("{$base}:k3:{$version}:{$environment}:{$this->getIpPrefix($context->ip)}:{$fingerprintHash}", $secret),
            'k4' => $context->accountId === null
                ? null
                : $this->hashKey("{$base}:k4:{$version}:{$environment}:{$context->accountId}", $secret),
            'k5' => $context->accountId === null || $fingerprintHash === null
                ? null
                : $this->hashKey("{$base}:k5:{$version}:{$environment}:{$context->accountId}:{$fingerprintHash}", $secret),
        ];

        if (filter_var($context->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $keys['k1_48'] = $this->hashKey(
                "{$base}:k1:{$version}:{$environment}:{$this->getIpPrefix($context->ip, 48)}",
                $secret
            );
            $keys['k1_40'] = $this->hashKey(
                "{$base}:k1:{$version}:{$environment}:{$this->getIpPrefix($context->ip, 40)}",
                $secret
            );
            $keys['k1_32'] = $this->hashKey(
                "{$base}:k1:{$version}:{$environment}:{$this->getIpPrefix($context->ip, 32)}",
                $secret
            );
        }

        return $keys;
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
                return substr($hex, 0, (int) ceil($cidr / 4));
            }
        }

        return $ip;
    }
}
