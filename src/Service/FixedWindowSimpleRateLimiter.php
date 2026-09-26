<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Production implementation of SimpleRateLimiterInterface (DEC-009).
 *
 * Version 1 is FIXED WINDOW, cost = 1 per consume, one policy-defined limit
 * and interval, one caller-supplied subject, atomic consume, and a
 * deterministic fixed reset boundary. It reuses the existing atomic
 * budget-epoch persistence primitives; no new storage backend family is
 * introduced. It is FAIL_CLOSED only and does not create score state,
 * correlation observations, or authentication budgets: its key namespace is
 * package-owned and distinct from every score/budget namespace.
 */
final class FixedWindowSimpleRateLimiter implements SimpleRateLimiterInterface
{
    /** @var array<string, SimpleThrottlePolicyInterface> */
    private readonly array $policies;

    /**
     * @param SimpleThrottlePolicyInterface[] $policies
     */
    public function __construct(
        array $policies,
        private readonly RateLimitStoreInterface $store,
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $keySecret,
        private readonly string $environmentScope,
        #[\SensitiveParameter]
        private readonly ?string $previousKeySecret = null,
    ) {
        $indexed = [];
        foreach ($policies as $policy) {
            $indexed[$policy->getName()] = $policy;
        }
        $this->policies = $indexed;
    }

    /**
     * @throws RateLimiterException When the policy is unregistered, the
     *     subject is blank, or previous-generation migration is required but
     *     the store lacks BudgetSeedStoreInterface.
     */
    public function consume(string $policyName, string $subject): SimpleRateLimitResultDTO
    {
        $policy = $this->policies[$policyName] ?? null;
        if ($policy === null) {
            throw new RateLimiterException(sprintf('Unknown simple throttle policy "%s".', $policyName));
        }

        if (trim($subject) === '') {
            throw new RateLimiterException('Simple throttle subject must not be empty or whitespace-only.');
        }

        $limit = $policy->getLimit();
        $intervalSeconds = $policy->getIntervalSeconds();

        $currentKey = $this->deriveKey($policyName, $limit, $intervalSeconds, $subject, $this->keySecret);
        $previousKey = $this->previousKeySecret === null
            ? null
            : $this->deriveKey($policyName, $limit, $intervalSeconds, $subject, $this->previousKeySecret);

        try {
            $state = $this->incrementAcrossRotation($currentKey, $previousKey, $intervalSeconds);
        } catch (RateLimiterException $configurationFailure) {
            throw $configurationFailure;
        } catch (\Throwable) {
            return new SimpleRateLimitResultDTO(
                allowed: false,
                limit: $limit,
                remaining: 0,
                retryAfter: null,
                resetAt: null,
                failureMode: SimpleRateLimitResultDTO::FAIL_CLOSED,
            );
        }

        $resetAt = $state->epochStart + $intervalSeconds;

        if ($state->count <= $limit) {
            return new SimpleRateLimitResultDTO(
                allowed: true,
                limit: $limit,
                remaining: max(0, $limit - $state->count),
                retryAfter: 0,
                resetAt: $resetAt,
                failureMode: SimpleRateLimitResultDTO::NORMAL,
            );
        }

        $now = $this->clock->now()->getTimestamp();

        return new SimpleRateLimitResultDTO(
            allowed: false,
            limit: $limit,
            remaining: 0,
            retryAfter: max(1, $resetAt - $now),
            resetAt: $resetAt,
            failureMode: SimpleRateLimitResultDTO::NORMAL,
        );
    }

    /**
     * Increment the fixed window across key rotation: Current is
     * authoritative when valid, a valid Previous is atomically seeded into
     * Current without being written to, and an absent/expired Previous
     * starts a normal Current epoch. Never a max()/sum() merge.
     *
     * @throws RateLimiterException When Previous holds a valid epoch but the
     *     store cannot atomically seed it into Current.
     */
    private function incrementAcrossRotation(string $currentKey, ?string $previousKey, int $intervalSeconds): BudgetStateDTO
    {
        $currentState = $this->store->getBudget($currentKey);
        if ($currentState !== null) {
            return $this->store->incrementBudget($currentKey, $intervalSeconds, 1);
        }

        if ($previousKey !== null) {
            $previousState = $this->store->getBudget($previousKey);
            if ($previousState !== null) {
                if (! $this->store instanceof BudgetSeedStoreInterface) {
                    throw new RateLimiterException(
                        'Simple fixed-window rotation migration requires the BudgetSeedStoreInterface '
                        . 'capability; the configured store cannot carry the previous-secret window into '
                        . 'the current generation without a silent reset.',
                    );
                }

                return $this->store->incrementBudgetWithSeed($currentKey, $intervalSeconds, $previousState, 1);
            }
        }

        return $this->store->incrementBudget($currentKey, $intervalSeconds, 1);
    }

    /**
     * Derive the physical storage key using canonical, collision-safe,
     * length-prefixed component encoding, HMAC-SHA256'd with the supplied
     * secret. The raw subject never crosses the store boundary directly.
     */
    private function deriveKey(
        string $policyName,
        int $limit,
        int $intervalSeconds,
        string $subject,
        #[\SensitiveParameter]
        string $secret,
    ): string {
        $preimage = self::encodeComponent('rate_limiter')
            . self::encodeComponent('simple_fixed_window')
            . self::encodeComponent('v1')
            . self::encodeComponent($this->environmentScope)
            . self::encodeComponent($policyName)
            . self::encodeComponent((string) $limit)
            . self::encodeComponent((string) $intervalSeconds)
            . self::encodeComponent($subject);

        return hash_hmac('sha256', $preimage, $secret);
    }

    private static function encodeComponent(string $component): string
    {
        return pack('N', strlen($component)) . $component;
    }
}
