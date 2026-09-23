<?php

declare(strict_types=1);

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * This example supplies small in-memory adapters for the package contracts.
 * Replace these adapters with the host application's atomic storage and
 * observability integrations in production.
 */
final class ExampleRateLimitStore implements HardBlockCycleStoreInterface
{
    private const PAUSE_HISTORY_RETENTION_SECONDS = 86400;

    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{cycles: list<int>, pauses: list<array{startedAt: int, until: int}>}> */
    private array $hardBlockCycles = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            $this->counters[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds,
            ];

            return $amount;
        }

        $current['value'] += $amount;
        $current['updatedAt'] = $now;
        $this->counters[$key] = $current;

        return $current['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            return null;
        }

        return new RateLimitStateDTO($current['value'], $current['updatedAt']);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds,
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->blocks[$key] = [
            'level' => $level,
            'expiresAt' => $this->clock->now()->getTimestamp() + $durationSeconds,
        ];
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
        $previousKey = $previousKey === $currentKey ? null : $previousKey;
        $state = $this->mergeHardBlockCycleStates(
            $this->hardBlockCycles[$currentKey] ?? null,
            $previousKey !== null ? ($this->hardBlockCycles[$previousKey] ?? null) : null,
            $now,
            $cycleWindowSeconds,
            $pauseHistoryRetentionSeconds,
        );
        $hadActiveHardBlock = false;
        foreach (array_unique(array_filter([$currentKey, $previousKey])) as $key) {
            $block = $this->blocks[$key] ?? null;
            $hadActiveHardBlock = $hadActiveHardBlock || ($block !== null && $block['level'] >= 2 && $block['expiresAt'] > $now);
        }

        $this->blocks[$currentKey] = ['level' => $level, 'expiresAt' => $now + $durationSeconds];
        $newCycle = ! $hadActiveHardBlock;
        if ($newCycle) {
            $state['cycles'][] = $now;
        }
        $pauseUntil = $this->activePauseUntil($state['pauses'], $now);
        $pauseActivated = false;
        if ($newCycle && count($state['cycles']) >= $cycleThreshold && $pauseUntil === 0) {
            $pauseUntil = $now + $pauseSeconds;
            $state['pauses'][] = ['startedAt' => $now, 'until' => $pauseUntil];
            $pauseActivated = true;
        }
        $this->hardBlockCycles[$currentKey] = $state;

        return new HardBlockCycleResultDTO($newCycle, count($state['cycles']), $pauseActivated, $pauseUntil);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): DecayPauseStateDTO
    {
        $state = $this->mergeHardBlockCycleStates(
            $this->hardBlockCycles[$currentKey] ?? null,
            $previousKey !== null && $previousKey !== $currentKey
                ? ($this->hardBlockCycles[$previousKey] ?? null)
                : null,
            $now,
            null,
            self::PAUSE_HISTORY_RETENTION_SECONDS,
        );
        if ($state['pauses'] === []) {
            return new DecayPauseStateDTO(0, 0);
        }

        $activePauseUntil = $this->activePauseUntil($state['pauses'], $now);
        if ($fromTimestamp >= $now) {
            return new DecayPauseStateDTO(0, $activePauseUntil);
        }

        $intervals = [];
        foreach ($state['pauses'] as $pause) {
            $start = max($fromTimestamp, $pause['startedAt']);
            $end = min($now, $pause['until']);
            if ($start < $end) {
                $intervals[] = [$start, $end];
            }
        }
        usort($intervals, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $elapsed = 0;
        $start = null;
        $end = null;
        foreach ($intervals as [$intervalStart, $intervalEnd]) {
            if ($start === null) {
                $start = $intervalStart;
                $end = $intervalEnd;
            } elseif ($intervalStart <= $end) {
                $end = max($end, $intervalEnd);
            } else {
                $elapsed += $end - $start;
                $start = $intervalStart;
                $end = $intervalEnd;
            }
        }
        if ($start !== null) {
            $elapsed += $end - $start;
        }

        return new DecayPauseStateDTO($elapsed, $activePauseUntil);
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $current = $this->blocks[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $current['expiresAt'] <= $now) {
            return null;
        }

        return new BlockStateDTO($current['level'], $current['expiresAt']);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $current = $this->budgets[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $now >= $current['epochStart'] + $current['epochDuration']) {
            return null;
        }

        return new BudgetStateDTO($current['count'], $current['epochStart']);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->budgets[$key] ?? null;

        if ($current === null || $now >= $current['epochStart'] + $current['epochDuration']) {
            $current = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $current['count'] += $amount;
        }

        $this->budgets[$key] = $current;

        return new BudgetStateDTO($current['count'], $current['epochStart']);
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /** @param list<array{startedAt: int, until: int}> $pauses */
    private function activePauseUntil(array $pauses, int $now): int
    {
        $active = 0;
        foreach ($pauses as $pause) {
            if ($pause['until'] > $now) {
                $active = max($active, $pause['until']);
            }
        }

        return $active;
    }

    /**
     * @param array{cycles: list<int>, pauses: list<array{startedAt: int, until: int}>}|null $current
     * @param array{cycles: list<int>, pauses: list<array{startedAt: int, until: int}>}|null $previous
     * @return array{cycles: list<int>, pauses: list<array{startedAt: int, until: int}>}
     */
    private function mergeHardBlockCycleStates(
        ?array $current,
        ?array $previous,
        int $now,
        ?int $cycleWindowSeconds,
        int $pauseHistoryRetentionSeconds,
    ): array {
        /** @var array<int, int> $cyclesByTimestamp */
        $cyclesByTimestamp = [];
        $pauses = [];
        $cycleCutoff = $cycleWindowSeconds === null ? null : $now - $cycleWindowSeconds;
        $pauseCutoff = $now - $pauseHistoryRetentionSeconds;

        foreach ([$current, $previous] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            foreach ($candidate['cycles'] as $transitionAt) {
                if ($cycleCutoff === null || $transitionAt >= $cycleCutoff) {
                    $cyclesByTimestamp[$transitionAt] = $transitionAt;
                }
            }

            foreach ($candidate['pauses'] as $pause) {
                if ($pause['until'] > $pauseCutoff) {
                    $pauses[] = $pause;
                }
            }
        }

        $cycles = array_values($cyclesByTimestamp);
        sort($cycles, SORT_NUMERIC);
        usort($pauses, static function (array $left, array $right): int {
            return ($left['startedAt'] <=> $right['startedAt'])
                ?: ($left['until'] <=> $right['until']);
        });

        /** @var list<array{startedAt: int, until: int}> $mergedPauses */
        $mergedPauses = [];
        foreach ($pauses as $pause) {
            $lastIndex = count($mergedPauses) - 1;
            if ($lastIndex >= 0 && $pause['startedAt'] <= $mergedPauses[$lastIndex]['until']) {
                $mergedPauses[$lastIndex]['until'] = max(
                    $mergedPauses[$lastIndex]['until'],
                    $pause['until'],
                );
                continue;
            }

            $mergedPauses[] = $pause;
        }

        return [
            'cycles' => $cycles,
            'pauses' => array_values((array) $mergedPauses),
        ];
    }
}

final class ExampleCorrelationStore implements BoundedCorrelationSnapshotStoreInterface
{
    /** @var array<string, array{items: array<string, true>, expiresAt: int}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int}> */
    private array $flags = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();
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

        $now = $this->clock->now()->getTimestamp();
        $current = $this->sets[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        if (isset($current['items'][$item])) {
            return new BoundedDistinctResultDTO(count($current['items']), true);
        }

        if (count($current['items']) >= $maxDistinct) {
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

        $now = $this->clock->now()->getTimestamp();
        $current = $this->sets[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        if (isset($current['items'][$item])) {
            return new BoundedDistinctSnapshotDTO(
                count($current['items']),
                true,
                false,
                array_keys($current['items']),
                $current['expiresAt'],
            );
        }

        if (count($current['items']) >= $maxDistinct) {
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
        $now = $this->clock->now()->getTimestamp();
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
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $current['expiresAt'] <= $now) {
            return 0;
        }

        return $current['count'];
    }
}

final class ExampleCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
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
        $expiresAt = $this->probeLeases[$policyName] ?? 0;
        if ($expiresAt > $now) {
            return false;
        }

        $this->probeLeases[$policyName] = $now + $leaseSeconds;

        return true;
    }
}

final class ExampleFailureSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void
    {
        // A production host would forward this signal to its observability boundary.
    }
}

$clock = new SystemClock(new DateTimeZone('UTC'));
$rateLimitStore = new ExampleRateLimitStore($clock);
$correlationStore = new ExampleCorrelationStore($clock);
$signalEmitter = new ExampleFailureSignalEmitter();

$pipeline = new EvaluationPipeline(
    $rateLimitStore,
    $correlationStore,
    new BudgetTracker($rateLimitStore, $clock),
    new AntiEquilibriumGate($correlationStore),
    new DecayCalculator($clock),
    new Maatify\RateLimiter\Service\EphemeralBucket($correlationStore),
    'example-key-secret',
    'example',
    $clock,
);

$limiter = new RateLimiterEngine(
    new DeviceIdentityResolver(new FingerprintHasher('example-fingerprint-secret')),
    $pipeline,
    new CircuitBreaker(new ExampleCircuitBreakerStore(), $signalEmitter, $clock),
    new FailureModeResolver(),
    $signalEmitter,
    $clock,
    [new LoginProtectionPolicy(), new OtpProtectionPolicy(), new ApiHeavyProtectionPolicy()],
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.10',
    ua: 'Mozilla/5.0 Chrome/123',
    accountId: 'account-123',
    clientFingerprint: ['platform' => 'web'],
    headers: ['Accept' => 'application/json'],
);

$results = [
    'pre_check' => $limiter->limit($context, RateLimitCommand::checkOnly('login_protection')),
    'first_failure' => $limiter->limit($context, RateLimitCommand::recordFailure('login_protection')),
    'second_failure' => $limiter->limit($context, RateLimitCommand::recordFailure('login_protection')),
    'success' => $limiter->limit($context, RateLimitCommand::recordSuccess('login_protection')),
];

foreach ($results as $name => $result) {
    echo $name . ': ' . json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
}
