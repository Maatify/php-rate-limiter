<?php

declare(strict_types=1);

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Minimal in-memory adapter for RateLimitStoreInterface plus the additive
 * PunishmentLifecycleStoreInterface capability required by the package's
 * default login_protection/otp_protection policies. Replace this with the
 * host application's atomic storage integration in production; see
 * examples/basic-rate-limit.php for a fully annotated version.
 */
final class SimpleThrottleExampleStore implements PunishmentLifecycleStoreInterface
{
    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $now = time();
        if (! isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] < $now) {
            $this->counters[$key] = ['value' => $amount, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];

            return $amount;
        }
        $this->counters[$key]['value'] += $amount;
        $this->counters[$key]['updatedAt'] = $now;

        return $this->counters[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = time();
        if (! isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] < $now) {
            return null;
        }

        return new RateLimitStateDTO($this->counters[$key]['value'], $this->counters[$key]['updatedAt']);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $now = time();
        $this->counters[$key] = ['value' => $value, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->blocks[$key] = ['level' => $level, 'expiresAt' => time() + $durationSeconds];
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $now = time();
        if (! isset($this->blocks[$key]) || $this->blocks[$key]['expiresAt'] <= $now) {
            return null;
        }

        return new BlockStateDTO($this->blocks[$key]['level'], $this->blocks[$key]['expiresAt']);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $now = time();
        if (! isset($this->budgets[$key]) || $now >= $this->budgets[$key]['epochStart'] + $this->budgets[$key]['epochDuration']) {
            return null;
        }

        return new BudgetStateDTO($this->budgets[$key]['count'], $this->budgets[$key]['epochStart']);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = time();
        if (! isset($this->budgets[$key]) || $now >= $this->budgets[$key]['epochStart'] + $this->budgets[$key]['epochDuration']) {
            $this->budgets[$key] = ['count' => $amount, 'epochStart' => $now, 'epochDuration' => $epochDurationSeconds];
        } else {
            $this->budgets[$key]['count'] += $amount;
        }

        return new BudgetStateDTO($this->budgets[$key]['count'], $this->budgets[$key]['epochStart']);
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO {
        $this->blocks[$currentKey] = ['level' => $level, 'expiresAt' => $now + $durationSeconds];

        return new HardBlockCycleResultDTO(true, 1, false, 0);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): DecayPauseStateDTO
    {
        return new DecayPauseStateDTO(0, 0);
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO
    {
        return null;
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
    {
        return new GenerationBoundScoreMutationDTO(false, null);
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        return new PunishmentLifecycleTransitionDTO(false, null, null, null);
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        return false;
    }
}

final class SimpleThrottleExampleCorrelationStore implements CorrelationStoreInterface
{
    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 1;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 1;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}

final class SimpleThrottleExampleCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void {}
}

final class SimpleThrottleExampleFailureSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void {}
}

// This example demonstrates only the generic/simple fixed-window throttling
// capability (DEC-009/DEC-010): one RateLimiterBuilder, one explicit
// FixedWindowThrottlePolicy registration, one build(), and consume().
$limiter = (new RateLimiterBuilder(
    new RateLimiterConfig(
        keySecret: 'example-key-secret',
        fingerprintSecret: 'example-fingerprint-secret',
        environmentScope: 'example',
    ),
    new SimpleThrottleExampleStore(),
    new SimpleThrottleExampleCorrelationStore(),
    new SimpleThrottleExampleCircuitBreakerStore(),
    new SimpleThrottleExampleFailureSignalEmitter(),
))
    ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy(
        name: 'checkout_attempts',
        limit: 3,
        intervalSeconds: 60,
    ))
    ->build();

$subject = 'customer-42';

for ($attempt = 1; $attempt <= 4; $attempt++) {
    $result = $limiter->consume('checkout_attempts', $subject);

    printf(
        "attempt #%d: allowed=%s remaining=%d retryAfter=%s resetAt=%s failureMode=%s\n",
        $attempt,
        $result->allowed ? 'true' : 'false',
        $result->remaining,
        $result->retryAfter === null ? 'null' : (string) $result->retryAfter,
        $result->resetAt === null ? 'null' : (string) $result->resetAt,
        $result->failureMode,
    );
}
