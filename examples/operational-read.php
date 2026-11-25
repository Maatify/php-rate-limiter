<?php

declare(strict_types=1);

use Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerProbeStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Small host-owned adapters used only to make this example executable. */
final class OperationalExampleRateLimitStore implements \Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface, OperationalReadOnlyStoreInterface, \Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface, \Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface, \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface
{
    private bool $accountLayout = false;
    private ?self $accountView = null;

    /** Example-local isolated in-memory account layout; production Redis shares a transaction through its official view. */
    public function e1Keyspace(): static
    {
        if ($this->accountLayout) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->accountLayout = true;
            $view->accountView = null;
            $view->writes = & $this->writes;
            $this->accountView = $view;
        }
        return $this->accountView;
    }

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
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;
        if ($current === null || $current['expiresAt'] <= $now) {
            $this->writes++;
            $this->counters[$key] = ['value' => $amount, 'updatedAt' => $now, 'expiresAt' => $now + $ttlSeconds];
        } else {
            // The contract of `RateLimitStoreInterface::increment()`: a result outside the caller's native `int` range is refused with
            // `RateLimiterException` before anything is changed (the write count included). The bound is compared first, so no sum is formed,
            // no float is produced and nothing is clamped or wrapped.
            if (($amount > 0 && $current['value'] > PHP_INT_MAX - $amount) || ($amount < 0 && $current['value'] < PHP_INT_MIN - $amount)) {
                throw new RateLimiterException('Score integer overflow.');
            }
            $this->writes++;
            $current['value'] += $amount;
            $current['updatedAt'] = $now;
            $this->counters[$key] = $current;
        }

        return $this->counters[$key]['value'];
    }

    public function incrementIfSnapshot(string $key, int $ttlSeconds, ?int $expectedValue, ?int $expectedUpdatedAt, int $newValue): ?int
    {
        $current = $this->get($key);
        $matches = $expectedValue === null
            ? $current === null
            : $current !== null && $current->value === $expectedValue && $current->updatedAt === $expectedUpdatedAt;
        if (! $matches) {
            return null;
        }
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = [
            'value' => $newValue,
            'updatedAt' => $now,
            'expiresAt' => $current === null ? $now + $ttlSeconds : $this->counters[$key]['expiresAt'],
        ];

        return $newValue;
    }

    public function replaceScore(string $key, int $value, int $ttlSeconds): ?RateLimitStateDTO
    {
        $previous = $this->get($key);
        $this->set($key, $value, $ttlSeconds);
        return $previous;
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

        $this->writes++;
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

    /**
     * #185 F-185-26 (DEC-032): Operational Read's block and budget reads. This store never deletes on read, so they
     * are its reads; a store that removes an expired key as it reads it must leave it in place here.
     */
    public function peekBlock(string $key): ?BlockStateDTO
    {
        return $this->checkBlock($key);
    }

    public function peekBudget(string $key): ?BudgetStateDTO
    {
        return $this->getBudget($key);
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

        /** @var array<int, array{startedAt: int, until: int}> $mergedPauses */
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
            'pauses' => array_values($mergedPauses),
        ];
    }

    /** @var array<string, array{generation: int, lifecycle: ?PostPunishmentReentryStateDTO}> */
    private array $generations = [];

    /** @var array<string, string> */
    private array $claimMarkers = [];

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $source = isset($this->counters[$currentKey]) && $this->counters[$currentKey]['expiresAt'] > $now ? GenerationBoundScoreStateDTO::SOURCE_CURRENT : GenerationBoundScoreStateDTO::SOURCE_PREVIOUS;
        $key = $source === GenerationBoundScoreStateDTO::SOURCE_CURRENT ? $currentKey : $previousKey;
        if ($key === null || !isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
            return null;
        }
        $generation = $this->generations[$key]['generation'] ?? null;
        $activeBlock = $this->checkBlock($currentKey);
        if ($activeBlock === null && $previousKey !== null) {
            $activeBlock = $this->checkBlock($previousKey);
        }
        $lifecycle = $activeBlock === null && $generation !== null ? $this->generations[$key]['lifecycle'] : null;
        return new GenerationBoundScoreStateDTO($source, $this->counters[$key]['value'], $this->counters[$key]['updatedAt'], $this->counters[$key]['expiresAt'], $generation, $lifecycle);
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
    {
        $observed = $this->readGenerationBoundScoreState($currentKey, $previousKey);
        if ($expectedState === null && $observed !== null || $expectedState !== null && ($observed === null || $observed->source !== $expectedState->source || $observed->generation !== $expectedState->generation || $observed->value !== $expectedState->value || $observed->updatedAt !== $expectedState->updatedAt || $observed->expiresAt !== $expectedState->expiresAt)) {
            return new GenerationBoundScoreMutationDTO(false, null);
        }
        $now = $this->clock->now()->getTimestamp();
        $generation = $observed === null ? 1 : ($observed->generation ?? 0) + 1;
        $expiresAt = $observed === null ? $now + $ttlSeconds : $observed->expiresAt;
        $this->counters[$currentKey] = ['value' => $newValue, 'updatedAt' => $now, 'expiresAt' => $expiresAt];
        $this->generations[$currentKey] = ['generation' => $generation, 'lifecycle' => null];
        unset($this->claimMarkers[$currentKey]);
        return new GenerationBoundScoreMutationDTO(true, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $newValue, $now, $expiresAt, $generation));
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        if ($expectedGeneration <= 0 || $level < 2 || $durationSeconds <= 0 || $cycleWindowSeconds <= 0 || $cycleThreshold <= 0 || $pauseSeconds <= 0 || $pauseHistoryRetentionSeconds <= 0) {
            throw new InvalidArgumentException('Invalid lifecycle publication parameters.');
        }
        $state = $this->readGenerationBoundScoreState($currentKey, $previousKey);
        if ($state === null || $state->source !== GenerationBoundScoreStateDTO::SOURCE_CURRENT || $state->generation !== $expectedGeneration) {
            // No Current generated score is a publishable source: an absent
            // source, a stale Current, or a structurally valid but merely
            // historical Previous are all ordinary optimistic conflicts.
            return new PunishmentLifecycleTransitionDTO(false, null, null, null);
        }
        $cycle = $this->blockWithCycleTracking($currentKey, $previousKey, $level, $durationSeconds, $this->clock->now()->getTimestamp(), $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        $now = $this->clock->now()->getTimestamp();
        $existingLifecycle = $this->generations[$currentKey]['lifecycle'] ?? null;
        if ($existingLifecycle !== null && $existingLifecycle->validUntil === $state->expiresAt) {
            // Same generation, same authoritative expiry: preserve the
            // already-published identity instead of adopting a new one.
            $lifecycleId = $existingLifecycle->id;
        } else {
            $lifecycleId = preg_match('/\A[a-f0-9]{32}\z/D', $proposedLifecycleId) === 1 ? $proposedLifecycleId : bin2hex(random_bytes(16));
        }
        $lifecycle = new PostPunishmentReentryStateDTO($lifecycleId, $state->expiresAt);
        $this->generations[$currentKey]['lifecycle'] = $lifecycle;
        return new PunishmentLifecycleTransitionDTO(true, $cycle, new BlockStateDTO($level, $now + $durationSeconds), $lifecycle);
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $currentBlock = $this->checkBlock($currentKey);
        $previousBlock = $previousKey === null ? null : $this->checkBlock($previousKey);
        if (($currentBlock === null ? 0 : $currentBlock->expiresAt) > $now || ($previousBlock === null ? 0 : $previousBlock->expiresAt) > $now) {
            return false;
        }
        $state = $this->readGenerationBoundScoreState($currentKey, $previousKey);
        if ($state?->postPunishmentReentry?->id !== $lifecycleId || $state->postPunishmentReentry->validUntil <= $now || isset($this->claimMarkers[$currentKey])) {
            return false;
        }
        $this->claimMarkers[$currentKey] = $lifecycleId;
        return true;
    }
}

final class OperationalExampleCorrelationStore implements \Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface, \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface
{
    private bool $accountLayout = false;
    private ?self $accountView = null;

    /** Example-local isolated in-memory account layout; production Redis shares a transaction through its official view. */
    public function e1Keyspace(): static
    {
        if ($this->accountLayout) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->accountLayout = true;
            $view->accountView = null;
            $this->accountView = $view;
        }
        return $this->accountView;
    }

    /** @var array<string, array<string, true>> */
    private array $boundedSets = [];

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new RuntimeException('correlation persistence is not configured in this read-only example');
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->fail();
    }
    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 0;
    }
    public function getWatchFlag(string $key): int
    {
        return 0;
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        $set = $this->boundedSets[$key] ?? [];
        if (isset($set[$item])) {
            return new BoundedDistinctResultDTO(count($set), true);
        }
        if (count($set) >= $maxDistinct) {
            return new BoundedDistinctResultDTO(count($set), false);
        }
        $set[$item] = true;
        $this->boundedSets[$key] = $set;

        return new BoundedDistinctResultDTO(count($set), true);
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
    new DecayCalculator($clock),
    'example-outer-secret',
    'example',
    $clock,
    $clock,
    null,
    null,
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
    // This focused Advanced Path example intentionally omits a device
    // fingerprint because the lightweight correlation adapter above does not
    // implement the bounded device-cap capability required for one.
    clientFingerprint: null,
);
$limiter->limit($context, RateLimitCommand::recordFailure('otp_protection'));

// The low-level/Advanced runtime setup above is used only to seed
// demonstrable state. The Production Default Read Path (DEC-012) itself is
// composed through RateLimiterBuilder, which resolves 'otp_protection' from
// its own registered policy graph instead of asking the Host to reconstruct
// the resolver, clock, decay calculator, secrets, or policy object a second
// time. This example does not claim that the same Builder instance composed
// enforcement; the Builder resolves the read graph for its own path.
$reader = (new RateLimiterBuilder(
    new RateLimiterConfig(
        keySecret: 'example-outer-secret',
        fingerprintSecret: 'example-fingerprint-secret',
        environmentScope: 'example',
    ),
    $store,
    $correlationStore,
    $circuitBreakerStore,
    $emitter,
))->buildOperationalReader();

$writesBeforeRead = $store->writeCount();
$snapshot = $reader->readScorePolicy($context, 'otp_protection');
if ($writesBeforeRead !== $store->writeCount()) {
    throw new RuntimeException('Operational read changed rate-limit storage state.');
}

echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
