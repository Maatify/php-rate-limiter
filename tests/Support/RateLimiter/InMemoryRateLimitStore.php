<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

class InMemoryRateLimitStore implements BudgetSeedStoreInterface, HardBlockCycleStoreInterface
{
    private const PAUSE_HISTORY_RETENTION_SECONDS = 86400;

    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $data = [];

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

        if (!isset($this->data[$key]) || $this->data[$key]['expiresAt'] < $now) {
            $this->data[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds,
            ];
            return $amount;
        }

        $this->data[$key]['value'] += $amount;
        $this->data[$key]['updatedAt'] = $now;
        return $this->data[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        if (!isset($this->data[$key]) || $this->data[$key]['expiresAt'] < $now) {
            return null;
        }

        return new RateLimitStateDTO(
            $this->data[$key]['value'],
            $this->data[$key]['updatedAt'],
        );
    }

    public function expiresAt(string $key): ?int
    {
        return $this->data[$key]['expiresAt'] ?? null;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->data[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds,
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->blocks[$key] = [
            'level' => $level,
            'expiresAt' => $now + $durationSeconds,
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
        $state = $this->mergeHardBlockCycleStates(
            $this->hardBlockCycles[$currentKey] ?? null,
            $previousKey !== null ? ($this->hardBlockCycles[$previousKey] ?? null) : null,
            $now,
            $cycleWindowSeconds,
            $pauseHistoryRetentionSeconds,
        );

        $hadActiveHardBlock = $this->hasActiveHardBlock($currentKey, $previousKey, $now);
        $this->writes++;
        $this->blocks[$currentKey] = [
            'level' => $level,
            'expiresAt' => $now + $durationSeconds,
        ];

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
            $activePauseUntil,
        );
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
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
        $now = $this->clock->now()->getTimestamp();
        if (!isset($this->budgets[$key])) {
            return null;
        }

        $epochStart = $this->budgets[$key]['epochStart'];
        $epochDuration = $this->budgets[$key]['epochDuration'];

        if ($now >= $epochStart + $epochDuration) {
            return null;
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart'],
        );
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->budgets[$key]) || $now >= $this->budgets[$key]['epochStart'] + $this->budgets[$key]['epochDuration']) {
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
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $current = $this->budgets[$key] ?? null;

        if ($current !== null && $now < $current['epochStart'] + $current['epochDuration']) {
            $current['count'] += $amount;
        } elseif ($now < $seed->epochStart + $epochDurationSeconds) {
            $current = [
                'count' => $seed->count + $amount,
                'epochStart' => $seed->epochStart,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $current = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        }

        $this->budgets[$key] = $current;

        return new BudgetStateDTO(
            $current['count'],
            $current['epochStart'],
        );
    }

    public function isHealthy(): bool
    {
        return true;
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
            'pauses' => array_values($mergedPauses),
        ];
    }
}
