<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Production implementation of SimpleRateLimitOperationalReaderInterface
 * (DEC-012).
 *
 * This reader derives the exact same DEC-013 state identity as
 * FixedWindowSimpleRateLimiter and uses only the read-only store operations
 * needed for inspection: getBudget() and isHealthy(). It never invokes
 * incrementBudget(), incrementBudgetWithSeed(), block(), set(), or another
 * mutation primitive.
 */
final class SimpleRateLimitOperationalReader implements SimpleRateLimitOperationalReaderInterface
{
    /** @var array<string, SimpleThrottlePolicyInterface> */
    private readonly array $policies;

    /**
     * @param SimpleThrottlePolicyInterface[] $policies
     * @param string $keySecret Active, non-blank key-generation secret. Not
     *     trimmed or otherwise normalized: accepted surrounding whitespace is
     *     retained byte-for-byte.
     * @param string $environmentScope Non-blank environment namespace included in derived keys.
     * @param ?string $previousKeySecret Optional previous-generation secret;
     *     null means no previous generation. When provided, it must not be
     *     empty or whitespace-only, and is likewise never trimmed or normalized.
     * @throws RateLimiterException When any supplied policy — including a
     *     directly implemented SimpleThrottlePolicyInterface, not only
     *     FixedWindowThrottlePolicy — has a blank name, a non-positive
     *     limit, or a non-positive interval; or when $keySecret or
     *     $environmentScope is empty or whitespace-only, or a non-null
     *     $previousKeySecret is empty or whitespace-only. Validation happens
     *     here, before any storage access can occur.
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
        if (trim($keySecret) === '') {
            throw new RateLimiterException('Active key secret must not be empty or whitespace-only.');
        }
        if (trim($environmentScope) === '') {
            throw new RateLimiterException('Environment scope must not be empty or whitespace-only.');
        }
        if ($previousKeySecret !== null && trim($previousKeySecret) === '') {
            throw new RateLimiterException('Previous key secret must not be empty or whitespace-only.');
        }

        $indexed = [];
        foreach ($policies as $policy) {
            self::assertValidPolicy($policy);
            $indexed[$policy->getName()] = $policy;
        }
        $this->policies = $indexed;
    }

    private static function assertValidPolicy(SimpleThrottlePolicyInterface $policy): void
    {
        if (trim($policy->getName()) === '') {
            throw new RateLimiterException('Simple throttle policy name must not be empty or whitespace-only.');
        }

        if ($policy->getLimit() <= 0) {
            throw new RateLimiterException(sprintf('Simple throttle policy "%s" limit must be a positive integer.', $policy->getName()));
        }

        if ($policy->getIntervalSeconds() <= 0) {
            throw new RateLimiterException(sprintf('Simple throttle policy "%s" interval must be a positive integer of seconds.', $policy->getName()));
        }
    }

    /**
     * Read one simple throttle policy's persisted fixed-window state for the
     * given subject without mutating it.
     *
     * Resolution order: Current always wins when its epoch is active;
     * Previous is used only when Current has no active epoch and a previous
     * key generation is configured. There is never a max()/sum() merge, and
     * an absent Previous never creates Current.
     *
     * @throws RateLimiterException When the policy is unregistered, the
     *     subject is blank, or the persisted effective reset boundary is not
     *     representable as a PHP integer. The reader remains read-only; this
     *     contract failure is not converted to an enforcement result.
     */
    public function read(string $policyName, string $subject): SimpleRateLimitOperationalSnapshotDTO
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
        $state = $this->store->getBudget($currentKey);
        $fromPreviousGeneration = false;

        if ($state === null && $this->previousKeySecret !== null) {
            $previousKey = $this->deriveKey($policyName, $limit, $intervalSeconds, $subject, $this->previousKeySecret);
            $previousState = $this->store->getBudget($previousKey);
            if ($previousState !== null) {
                $state = $previousState;
                $fromPreviousGeneration = true;
            }
        }

        $count = $state === null ? 0 : $state->count;
        $epochStart = $state?->epochStart;
        $resetAt = $epochStart === null ? null : self::resetAt($epochStart, $intervalSeconds);

        return new SimpleRateLimitOperationalSnapshotDTO(
            $policyName,
            $this->clock->now()->getTimestamp(),
            $this->store->isHealthy(),
            $limit,
            $intervalSeconds,
            $count,
            max(0, $limit - $count),
            $epochStart,
            $resetAt,
            $fromPreviousGeneration,
        );
    }

    /**
     * Derive the physical storage key using the exact same canonical,
     * collision-safe, length-prefixed component encoding and HMAC-SHA256
     * scheme as FixedWindowSimpleRateLimiter::deriveKey(), so read() resolves
     * the exact same state identity that consume() writes. The raw subject
     * never crosses the store boundary directly.
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

    private static function resetAt(int $epochStart, int $intervalSeconds): int
    {
        if ($epochStart > PHP_INT_MAX - $intervalSeconds) {
            throw new RateLimiterException(
                'Simple fixed-window reset boundary must be representable as a PHP integer.',
            );
        }

        return $epochStart + $intervalSeconds;
    }
}
