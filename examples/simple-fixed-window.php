<?php

declare(strict_types=1);

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
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

final class SimpleThrottleExampleCorrelationStore implements BoundedCorrelationSnapshotStoreInterface
{
    /** @var array<string, array{items: array<string, true>, expiresAt: int}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int}> */
    private array $flags = [];

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('Correlation TTL must be positive.');
        }

        $now = time();
        $current = $this->sets[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        $current['items'][$item] = true;
        $this->sets[$key] = $current;

        return count($current['items']);
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        if ($ttlSeconds <= 0 || $maxDistinct <= 0) {
            throw new InvalidArgumentException('Bounded correlation TTL and cap must be positive.');
        }

        $now = time();
        $current = $this->sets[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        if (isset($current['items'][$item])) {
            $this->sets[$key] = $current;

            return new BoundedDistinctResultDTO(count($current['items']), true);
        }

        if (count($current['items']) >= $maxDistinct) {
            $this->sets[$key] = $current;

            return new BoundedDistinctResultDTO(count($current['items']), false);
        }

        $current['items'][$item] = true;
        $this->sets[$key] = $current;

        return new BoundedDistinctResultDTO(count($current['items']), true);
    }

    public function addDistinctBoundedWithSnapshot(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctSnapshotDTO {
        if ($ttlSeconds <= 0 || $maxDistinct <= 0) {
            throw new InvalidArgumentException('Bounded correlation TTL and cap must be positive.');
        }

        $now = time();
        $current = $this->sets[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        if (isset($current['items'][$item])) {
            $this->sets[$key] = $current;

            return new BoundedDistinctSnapshotDTO(
                count($current['items']),
                true,
                false,
                array_keys($current['items']),
                $current['expiresAt'],
            );
        }

        if (count($current['items']) >= $maxDistinct) {
            $this->sets[$key] = $current;

            return new BoundedDistinctSnapshotDTO(
                count($current['items']),
                false,
                false,
                array_keys($current['items']),
                $current['expiresAt'],
            );
        }

        $current['items'][$item] = true;
        $this->sets[$key] = $current;

        return new BoundedDistinctSnapshotDTO(
            count($current['items']),
            true,
            true,
            array_keys($current['items']),
            $current['expiresAt'],
        );
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('Correlation flag TTL must be positive.');
        }

        $now = time();
        $current = $this->flags[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['count' => 0, 'expiresAt' => $now + $ttlSeconds];
        }

        $current['count']++;
        $this->flags[$key] = $current;

        return $current['count'];
    }

    public function getWatchFlag(string $key): int
    {
        $current = $this->flags[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= time()) {
            return 0;
        }

        return $current['count'];
    }
}

final class SimpleThrottleExampleCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    /** @var array<string, int> */
    private array $probeLeases = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        if ($leaseSeconds <= 0) {
            throw new InvalidArgumentException('Circuit-breaker probe lease must be positive.');
        }

        $expiresAt = $this->probeLeases[$policyName] ?? 0;
        if ($expiresAt > $now) {
            return false;
        }

        $this->probeLeases[$policyName] = $now + $leaseSeconds;

        return true;
    }
}

final class SimpleThrottleExampleFailureSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void {}
}

// This example demonstrates simple fixed-window throttling: one
// RateLimiterBuilder, one explicit FixedWindowThrottlePolicy registration,
// one build(), and consume(). The default Builder still constructs the
// composite package graph, so these small adapters provide the minimum
// current score-runtime capabilities even though this sample only calls
// consume(). The same Builder instance is reused below to obtain the
// Production Default Read Path (DEC-012).
$builder = (new RateLimiterBuilder(
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
    ));

$limiter = $builder->build();

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

$weighted = $limiter->consume('checkout_attempts', $subject, 2);
printf("weighted consume cost=2: allowed=%s remaining=%d\n", $weighted->allowed ? 'true' : 'false', $weighted->remaining);

// Operational Read reuses the same Builder instance: it observes the
// persisted fixed-window state the consumes above already wrote, without
// itself performing a consume.
$reader = $builder->buildOperationalReader();
$beforeSecondRead = $reader->readSimpleThrottle('checkout_attempts', $subject);
$afterSecondRead = $reader->readSimpleThrottle('checkout_attempts', $subject);

if ($beforeSecondRead->count !== 6) {
    throw new RuntimeException(sprintf('Expected weighted persisted count 6, got %d.', $beforeSecondRead->count));
}

if ($beforeSecondRead->count !== $afterSecondRead->count) {
    throw new RuntimeException('Operational read must not create a new consume.');
}

printf(
    "operational read: count=%d remaining=%d resetAt=%s fromPreviousGeneration=%s\n",
    $afterSecondRead->count,
    $afterSecondRead->remaining,
    $afterSecondRead->resetAt === null ? 'null' : (string) $afterSecondRead->resetAt,
    $afterSecondRead->fromPreviousGeneration ? 'true' : 'false',
);
