<?php

declare(strict_types=1);

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Small host-owned adapters used only to make this example executable. */
final class OperationalExampleRateLimitStore implements HardBlockCycleStoreInterface
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

    private int $writes = 0;

    public function __construct(private readonly ClockInterface $clock) {}

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $this->counters[$key] = ['value' => $amount, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];
        } else {
            $current['value'] += $amount;
            $current['updatedAt'] = $now;
            $this->counters[$key] = $current;
        }

        return $this->counters[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $current = $this->counters[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();
        return $current !== null && $current['expiresAt'] > $now
            ? new RateLimitStateDTO($current['value'], $current['updatedAt'])
            : null;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = ['value' => $value, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->writes++;
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
        $state = $this->hardBlockCycles[$currentKey] ?? $this->hardBlockCycles[$previousKey ?? ''] ?? ['cycles' => [], 'pauses' => []];
        $hadActiveHardBlock = false;
        foreach (array_unique(array_filter([$currentKey, $previousKey])) as $key) {
            $block = $this->blocks[$key] ?? null;
            $hadActiveHardBlock = $hadActiveHardBlock || ($block !== null && $block['level'] >= 2 && $block['expiresAt'] > $now);
        }

        $this->writes++;
        $this->blocks[$currentKey] = ['level' => $level, 'expiresAt' => $now + $durationSeconds];
        $state['cycles'] = array_values(array_filter($state['cycles'], static fn(int $at): bool => $at >= $now - $cycleWindowSeconds));
        $state['pauses'] = array_values(array_filter($state['pauses'], static fn(array $pause): bool => $pause['until'] > $now - $pauseHistoryRetentionSeconds));
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
        $state = $this->hardBlockCycles[$currentKey] ?? $this->hardBlockCycles[$previousKey ?? ''] ?? null;
        if ($state === null || $fromTimestamp >= $now) {
            return new DecayPauseStateDTO(0, 0);
        }
        $intervals = [];
        $pauseCutoff = $now - self::PAUSE_HISTORY_RETENTION_SECONDS;
        foreach ($state['pauses'] as $pause) {
            if ($pause['until'] <= $pauseCutoff) {
                continue;
            }
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

        return new DecayPauseStateDTO($elapsed, $this->activePauseUntil($state['pauses'], $now));
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $current = $this->blocks[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();
        return $current !== null && $current['expiresAt'] > $now
            ? new BlockStateDTO($current['level'], $current['expiresAt'])
            : null;
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $current = $this->budgets[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();
        return $current !== null && $current['epochStart'] + $current['epochDuration'] > $now
            ? new BudgetStateDTO($current['count'], $current['epochStart'])
            : null;
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->budgets[$key] ?? null;
        if ($current === null || $current['epochStart'] + $current['epochDuration'] <= $now) {
            $current = ['count' => $amount, 'epochStart' => $now, 'epochDuration' => $epochDurationSeconds];
        } else {
            $current['count'] += $amount;
        }
        $this->writes++;
        $this->budgets[$key] = $current;
        return new BudgetStateDTO($current['count'], $current['epochStart']);
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function writeCount(): int
    {
        return $this->writes;
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
}

final class OperationalExampleCorrelationStore implements CorrelationStoreInterface
{
    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 0;
    }
    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 0;
    }
    public function getWatchFlag(string $key): int
    {
        return 0;
    }
}

final class OperationalExampleCircuitBreakerStore implements CircuitBreakerProbeStoreInterface
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

final class OperationalExampleSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void {}
}

$clock = new SystemClock(new DateTimeZone('UTC'));
$store = new OperationalExampleRateLimitStore($clock);
$correlationStore = new OperationalExampleCorrelationStore();
$circuitBreakerStore = new OperationalExampleCircuitBreakerStore();
$emitter = new OperationalExampleSignalEmitter();
$pipeline = new EvaluationPipeline(
    $store,
    $correlationStore,
    new BudgetTracker($store, $clock),
    new AntiEquilibriumGate($correlationStore),
    new DecayCalculator($clock),
    new EphemeralBucket($correlationStore),
    'example-outer-secret',
    'example',
    $clock,
);
$limiter = new RateLimiterEngine(
    new DeviceIdentityResolver(new FingerprintHasher('example-fingerprint-secret')),
    $pipeline,
    new CircuitBreaker($circuitBreakerStore, $emitter, $clock),
    new FailureModeResolver(),
    $emitter,
    $clock,
    [new OtpProtectionPolicy()],
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.20',
    ua: 'Mozilla/5.0 Chrome/123',
    accountId: 'account-123',
    clientFingerprint: ['platform' => 'web'],
);
$limiter->limit($context, RateLimitCommand::recordFailure('otp_protection'));

$reader = new RateLimitOperationalReader(
    new DeviceIdentityResolver(new FingerprintHasher('example-fingerprint-secret')),
    $store,
    $circuitBreakerStore,
    new DecayCalculator($clock),
    $clock,
    'example-outer-secret',
    'example',
);
$writesBeforeRead = $store->writeCount();
$snapshot = $reader->read($context, new OtpProtectionPolicy());
if ($writesBeforeRead !== $store->writeCount()) {
    throw new RuntimeException('Operational read changed rate-limit storage state.');
}

echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
