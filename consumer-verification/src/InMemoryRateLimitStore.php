<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class InMemoryRateLimitStore implements BudgetSeedStoreInterface, HardBlockCycleStoreInterface
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

    private int $operations = 0;

    private int $writes = 0;

    public function __construct(private readonly ClockInterface $clock) {}

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
            $this->counters[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds,
            ];

            return $amount;
        }

        $this->counters[$key]['value'] += $amount;
        $this->counters[$key]['updatedAt'] = $now;

        return $this->counters[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
            return null;
        }

        return new RateLimitStateDTO(
            $this->counters[$key]['value'],
            $this->counters[$key]['updatedAt'],
        );
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds,
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->operations++;
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
        if ($level < 2 || $durationSeconds <= 0 || $cycleWindowSeconds <= 0 || $cycleThreshold <= 0
            || $pauseSeconds <= 0 || $pauseHistoryRetentionSeconds <= 0) {
            throw new \InvalidArgumentException('Hard-block cycle parameters must be positive and use level 2 or higher.');
        }

        $previousKey = $previousKey === $currentKey ? null : $previousKey;
        $state = $this->hardBlockCycles[$currentKey] ?? null;
        if ($state === null && $previousKey !== null) {
            $state = $this->hardBlockCycles[$previousKey] ?? null;
        }
        $state ??= ['cycles' => [], 'pauses' => []];

        $hadActiveHardBlock = $this->hasActiveHardBlock($currentKey, $previousKey, $now);
        $this->writes++;
        $this->blocks[$currentKey] = [
            'level' => $level,
            'expiresAt' => $now + $durationSeconds,
        ];

        $cycleCutoff = $now - $cycleWindowSeconds;
        $state['cycles'] = array_values(array_filter(
            $state['cycles'],
            static fn(int $transitionAt): bool => $transitionAt >= $cycleCutoff,
        ));
        $pauseCutoff = $now - $pauseHistoryRetentionSeconds;
        $state['pauses'] = array_values(array_filter(
            $state['pauses'],
            static fn(array $pause): bool => $pause['until'] > $pauseCutoff,
        ));

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

        return new HardBlockCycleResultDTO(
            $newCycle,
            count($state['cycles']),
            $pauseActivated,
            $pauseUntil,
        );
    }

    public function readDecayPauseState(
        string $currentKey,
        ?string $previousKey,
        int $fromTimestamp,
        int $now,
    ): DecayPauseStateDTO {
        $state = $this->hardBlockCycles[$currentKey] ?? null;
        if ($state === null && $previousKey !== null && $previousKey !== $currentKey) {
            $state = $this->hardBlockCycles[$previousKey] ?? null;
        }

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

        $elapsedPausedSeconds = 0;
        $mergedStart = null;
        $mergedEnd = null;
        foreach ($intervals as [$start, $end]) {
            if ($mergedStart === null) {
                $mergedStart = $start;
                $mergedEnd = $end;
                continue;
            }
            if ($start <= $mergedEnd) {
                $mergedEnd = max($mergedEnd, $end);
                continue;
            }
            $elapsedPausedSeconds += $mergedEnd - $mergedStart;
            $mergedStart = $start;
            $mergedEnd = $end;
        }
        if ($mergedStart !== null) {
            $elapsedPausedSeconds += $mergedEnd - $mergedStart;
        }

        return new DecayPauseStateDTO(
            $elapsedPausedSeconds,
            $this->activePauseUntil($state['pauses'], $now),
        );
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->blocks[$key]) || $this->blocks[$key]['expiresAt'] <= $now) {
            return null;
        }

        return new BlockStateDTO(
            $this->blocks[$key]['level'],
            $this->blocks[$key]['expiresAt'],
        );
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $this->operations++;
        $budget = $this->budgets[$key] ?? null;
        if ($budget === null) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now >= $budget['epochStart'] + $budget['epochDuration']) {
            return null;
        }

        return new BudgetStateDTO($budget['count'], $budget['epochStart']);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $budget = $this->budgets[$key] ?? null;

        if ($budget === null || $now >= $budget['epochStart'] + $budget['epochDuration']) {
            $this->budgets[$key] = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $this->budgets[$key]['count'] += $amount;
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart'],
        );
    }

    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1,
    ): BudgetStateDTO {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $budget = $this->budgets[$key] ?? null;

        if ($budget !== null && $now < $budget['epochStart'] + $budget['epochDuration']) {
            $this->budgets[$key]['count'] += $amount;
        } elseif ($now < $seed->epochStart + $epochDurationSeconds) {
            $this->budgets[$key] = [
                'count' => $seed->count + $amount,
                'epochStart' => $seed->epochStart,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $this->budgets[$key] = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart'],
        );
    }

    public function isHealthy(): bool
    {
        $this->operations++;

        return true;
    }

    public function operationCount(): int
    {
        return $this->operations;
    }

    public function writeCount(): int
    {
        return $this->writes;
    }

    private function hasActiveHardBlock(string $currentKey, ?string $previousKey, int $now): bool
    {
        foreach (array_unique(array_filter([$currentKey, $previousKey])) as $key) {
            $block = $this->blocks[$key] ?? null;
            if ($block !== null && $block['level'] >= 2 && $block['expiresAt'] > $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{startedAt: int, until: int}> $pauses
     */
    private function activePauseUntil(array $pauses, int $now): int
    {
        $activePauseUntil = 0;
        foreach ($pauses as $pause) {
            if ($pause['until'] > $now) {
                $activePauseUntil = max($activePauseUntil, $pause['until']);
            }
        }

        return $activePauseUntil;
    }
}
